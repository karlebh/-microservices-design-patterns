<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\EvolutionaryArchitecture\ConsumerContract;
use Patterns\Decomposition\EvolutionaryArchitecture\ContractVerifier;
use Patterns\Decomposition\EvolutionaryArchitecture\DependencyGraph;
use Patterns\Decomposition\EvolutionaryArchitecture\SourceScanner;
use PHPUnit\Framework\TestCase;

final class EvolutionaryArchitectureTest extends TestCase
{
    private const ENDPOINT = 'GET /customers/{id}';

    /**
     * Pattern folders in src/ must not depend on each other, except for
     * these deliberate compositions (a pattern that is BUILT FROM another).
     */
    private const INTENTIONAL_COMPOSITIONS = [
        'DataConsistency\OutboxCdc' => ['DataArchitecture\ChangeDataCapture'],
    ];

    // ---- Consumer-driven contracts ------------------------------------------------------

    private function verifier(): ContractVerifier
    {
        $verifier = new ContractVerifier;
        $verifier->register(new ConsumerContract('billing', self::ENDPOINT, ['id' => 'int', 'name' => 'string']));
        $verifier->register(new ConsumerContract('shipping', self::ENDPOINT, ['id' => 'int', 'address.city' => 'string']));

        return $verifier;
    }

    private function response(array $overrides = []): array
    {
        return [self::ENDPOINT => $overrides + [
            'id' => 7,
            'name' => 'Ada Lovelace',
            'address' => ['city' => 'London', 'zip' => 'N1'],
        ]];
    }

    public function test_adding_fields_never_breaks_a_consumer(): void
    {
        $candidate = $this->response(['loyalty_tier' => 'gold', 'created_at' => '2026-01-01']);

        $this->assertTrue($this->verifier()->canDeploy($candidate), 'Consumers only assert on what they read.');
    }

    public function test_removing_a_field_a_consumer_reads_blocks_the_deployment(): void
    {
        $candidate = $this->response();
        unset($candidate[self::ENDPOINT]['name']);

        $this->assertSame(
            ['billing: [GET /customers/{id}] is missing field [name]'],
            $this->verifier()->verify($candidate),
            'The provider learns WHO it would break, in its own pipeline.'
        );
    }

    public function test_changing_a_type_or_a_nested_field_is_caught_too(): void
    {
        $retyped = $this->response(['id' => '7']);
        $restructured = $this->response(['address' => 'London N1']);

        $this->assertStringContainsString('field [id] must be int, got string', $this->verifier()->verify($retyped)[0]);
        $this->assertSame(
            ['shipping: [GET /customers/{id}] is missing field [address.city]'],
            $this->verifier()->verify($restructured),
        );
    }

    public function test_removing_an_endpoint_breaks_everyone_who_calls_it(): void
    {
        $this->assertCount(2, $this->verifier()->verify([]));
    }

    public function test_expand_and_contract_makes_a_breaking_change_in_independently_deployable_steps(): void
    {
        $verifier = $this->verifier();
        $crm = new ConsumerContract('crm', self::ENDPOINT, ['name' => 'string']);
        $verifier->register($crm);

        // The provider wants to rename `name` to `full_name`.
        $renamedInOneGo = $this->response(['full_name' => 'Ada Lovelace']);
        unset($renamedInOneGo[self::ENDPOINT]['name']);
        $this->assertFalse($verifier->canDeploy($renamedInOneGo), 'A direct rename would need three teams to deploy at once.');

        // 1. EXPAND: serve both. Nobody notices.
        $expanded = $this->response(['full_name' => 'Ada Lovelace']);
        $this->assertTrue($verifier->canDeploy($expanded));
        $this->assertSame(['billing', 'crm'], $verifier->consumersOf(self::ENDPOINT, 'name'));

        // 2. MIGRATE: each consumer switches when it suits them.
        $verifier->register(new ConsumerContract('billing', self::ENDPOINT, ['id' => 'int', 'full_name' => 'string']));
        $this->assertTrue($verifier->canDeploy($expanded));
        $this->assertSame(['crm'], $verifier->consumersOf(self::ENDPOINT, 'name'), 'Not yet safe to remove.');

        $verifier->register(new ConsumerContract('crm', self::ENDPOINT, ['full_name' => 'string']));
        $this->assertSame([], $verifier->consumersOf(self::ENDPOINT, 'name'));

        // 3. CONTRACT: the old field has no readers left and can go.
        $this->assertTrue($verifier->canDeploy($renamedInOneGo));
    }

    // ---- Fitness functions --------------------------------------------------------------

    public function test_fitness_function_no_dependency_cycles(): void
    {
        $healthy = DependencyGraph::fromArray([
            'orders' => ['payments', 'catalog'],
            'payments' => ['ledger'],
        ]);

        $tangled = DependencyGraph::fromArray([
            'orders' => ['payments'],
            'payments' => ['notifications'],
            'notifications' => ['orders'], // closes the loop
            'catalog' => [],
        ]);

        $this->assertSame([], $healthy->cycles());
        $this->assertSame([['notifications', 'orders', 'payments', 'notifications']], $tangled->cycles());
    }

    public function test_fitness_function_layers_only_depend_downwards(): void
    {
        $graph = DependencyGraph::fromArray([
            'app.http' => ['app.domain'],
            'app.domain' => ['app.infrastructure.mysql'], // the domain reaching into infrastructure
            'app.infrastructure.mysql' => [],
        ]);

        $this->assertSame(
            ['app.domain -> app.infrastructure.mysql'],
            $graph->forbiddenDependencies(['app.domain' => ['app.infrastructure', 'app.http']]),
        );
    }

    public function test_fitness_function_limits_how_much_one_service_depends_on(): void
    {
        $graph = DependencyGraph::fromArray([
            'checkout' => ['cart', 'pricing', 'stock', 'payments', 'shipping', 'tax', 'fraud'],
            'cart' => ['pricing'],
        ]);

        $this->assertSame(7, $graph->fanOut('checkout'), 'Above a budget of, say, 5: checkout is turning into a god service.');
        $this->assertSame(1, $graph->fanOut('cart'));
    }

    public function test_the_graph_shows_the_blast_radius_of_a_change(): void
    {
        $graph = DependencyGraph::fromArray([
            'web' => ['orders'],
            'orders' => ['payments'],
            'payments' => ['ledger'],
            'reports' => ['ledger'],
            'catalog' => [],
        ]);

        $this->assertSame(['orders', 'payments', 'reports', 'web'], $graph->dependentsOf('ledger'));
        $this->assertSame([], $graph->dependentsOf('web'), 'Nothing depends on the edge of the system.');
    }

    // ---- The same checks, run on this repository ----------------------------------------

    private function thisRepository(): DependencyGraph
    {
        return SourceScanner::scan(
            directory: dirname(__DIR__, 3).'/src',
            rootNamespace: 'Patterns',
            depth: 2,
            ignoreFiles: ['PatternsServiceProvider.php'], // the composition root is allowed to know every pattern
        );
    }

    public function test_the_scanner_reads_dependencies_from_real_source_code(): void
    {
        $this->assertContains(
            'DataArchitecture\ChangeDataCapture',
            $this->thisRepository()->dependenciesOf('DataConsistency\OutboxCdc'),
        );
    }

    public function test_this_repository_has_no_cycles_between_patterns(): void
    {
        $this->assertSame([], $this->thisRepository()->cycles());
    }

    public function test_this_repository_keeps_every_pattern_folder_independent(): void
    {
        $graph = $this->thisRepository();
        $unexpected = [];

        foreach (array_keys(self::INTENTIONAL_COMPOSITIONS) as $pattern) {
            $this->assertNotSame([], $graph->dependenciesOf($pattern), "[{$pattern}] is listed as a composition but depends on nothing.");
        }

        foreach ($this->patternsIn($graph) as $pattern) {
            foreach ($graph->dependenciesOf($pattern) as $dependency) {
                $allowed = $dependency === 'Support' || in_array($dependency, self::INTENTIONAL_COMPOSITIONS[$pattern] ?? [], true);

                if (! $allowed) {
                    $unexpected[] = "{$pattern} -> {$dependency}";
                }
            }
        }

        $this->assertSame([], $unexpected, 'A pattern folder should be readable on its own. If this dependency is deliberate, add it to INTENTIONAL_COMPOSITIONS.');
    }

    /** @return list<string> every "Section\Pattern" module that has source files */
    private function patternsIn(DependencyGraph $graph): array
    {
        $patterns = [];

        foreach (glob(dirname(__DIR__, 3).'/src/*/*', GLOB_ONLYDIR) as $directory) {
            $patterns[] = basename(dirname($directory)).'\\'.basename($directory);
        }

        return $patterns;
    }
}
