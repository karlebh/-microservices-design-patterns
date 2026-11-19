<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\StranglerFig\StranglerFacade;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StranglerFigTest extends TestCase
{
    private function facade(?callable $modern = null): StranglerFacade
    {
        return new StranglerFacade(
            legacy: fn (string $path) => "legacy response for {$path}",
            modern: ($modern ?? fn (string $path) => "modern response for {$path}")(...),
        );
    }

    public function test_at_the_start_the_facade_forwards_everything_to_the_legacy_system(): void
    {
        $facade = $this->facade();

        $this->assertSame('legacy', $facade->handle('/invoices/1')['servedBy']);
        $this->assertSame('legacy', $facade->handle('/orders/1')['servedBy']);
    }

    public function test_features_move_to_the_new_system_one_route_at_a_time(): void
    {
        $facade = $this->facade();

        $facade->migrate('/invoices');

        $this->assertSame('modern', $facade->handle('/invoices/1')['servedBy']);
        $this->assertSame('legacy', $facade->handle('/orders/1')['servedBy'], 'Untouched routes keep working as before.');

        $facade->migrate('/orders');

        $this->assertSame('modern', $facade->handle('/orders/1')['servedBy']);
    }

    public function test_progress_reaches_one_when_the_legacy_system_can_be_switched_off(): void
    {
        $facade = $this->facade();
        $routes = ['/invoices', '/orders', '/customers', '/reports'];

        $this->assertSame(0.0, $facade->progress($routes));

        $facade->migrate('/invoices');
        $facade->migrate('/orders', 50); // still rolling out: does not count as done
        $this->assertSame(0.25, $facade->progress($routes));

        foreach ($routes as $route) {
            $facade->migrate($route);
        }

        $this->assertSame(1.0, $facade->progress($routes));
    }

    public function test_a_route_can_be_rolled_out_to_a_percentage_of_users(): void
    {
        $facade = $this->facade();
        $facade->migrate('/invoices', 20);

        $modern = 0;

        for ($user = 1; $user <= 1_000; $user++) {
            if ($facade->handle('/invoices/1', "user-{$user}")['servedBy'] === 'modern') {
                $modern++;
            }
        }

        $this->assertEqualsWithDelta(200, $modern, 60, 'Roughly a fifth of users are on the new system.');
    }

    public function test_a_user_stays_on_the_same_side_while_the_rollout_grows(): void
    {
        $facade = $this->facade();
        $facade->migrate('/invoices', 20);

        $earlyAdopters = array_filter(
            array_map(fn (int $i) => "user-{$i}", range(1, 300)),
            fn (string $user) => $facade->handle('/invoices/1', $user)['servedBy'] === 'modern',
        );

        // Same request, same user: always the same answer.
        foreach ($earlyAdopters as $user) {
            $this->assertSame('modern', $facade->handle('/invoices/1', $user)['servedBy']);
        }

        // Ramping up only ADDS users; nobody is sent back to legacy.
        $facade->migrate('/invoices', 60);

        foreach ($earlyAdopters as $user) {
            $this->assertSame('modern', $facade->handle('/invoices/1', $user)['servedBy']);
        }
    }

    public function test_rolling_back_is_a_routing_change(): void
    {
        $facade = $this->facade();
        $facade->migrate('/invoices');
        $this->assertSame('modern', $facade->handle('/invoices/1')['servedBy']);

        $facade->migrate('/invoices', 0);

        $this->assertSame('legacy', $facade->handle('/invoices/1')['servedBy']);
    }

    public function test_the_legacy_system_is_the_safety_net_when_the_new_one_fails(): void
    {
        $facade = $this->facade(fn () => throw new RuntimeException('new service has a bug'));
        $facade->migrate('/invoices');

        $response = $facade->handle('/invoices/1');

        $this->assertSame('legacy', $response['servedBy'], 'The user still gets an answer.');
        $this->assertSame(['/invoices/1'], $facade->fallbacks, 'And the failure is recorded, not hidden.');
    }

    public function test_shadowing_compares_both_systems_without_users_noticing(): void
    {
        $facade = $this->facade(fn (string $path) => $path === '/reports/tax'
            ? 'a different total'                       // the new code has a bug here
            : "legacy response for {$path}");           // elsewhere it agrees with legacy

        $facade->shadow('/reports');

        $this->assertSame('legacy', $facade->handle('/reports/sales')['servedBy']);
        $this->assertSame('legacy response for /reports/tax', $facade->handle('/reports/tax')['body'], 'Users always get the legacy answer.');

        $this->assertCount(1, $facade->mismatches, 'Only the request where the two systems disagreed.');
        $this->assertSame('/reports/tax', $facade->mismatches[0]['path']);
    }

    public function test_the_most_specific_route_wins(): void
    {
        $facade = $this->facade();
        $facade->migrate('/orders');
        $facade->migrate('/orders/export', 0); // this one part is not ready yet

        $this->assertSame('modern', $facade->handle('/orders/42')['servedBy']);
        $this->assertSame('legacy', $facade->handle('/orders/export/csv')['servedBy']);
    }
}
