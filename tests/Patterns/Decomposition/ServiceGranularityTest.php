<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\ServiceGranularity\Capability;
use Patterns\Decomposition\ServiceGranularity\DecompositionPlanner;
use Patterns\Decomposition\ServiceGranularity\GranularityAdvice;
use Patterns\Decomposition\ServiceGranularity\GranularityAnalyzer;
use PHPUnit\Framework\TestCase;

final class ServiceGranularityTest extends TestCase
{
    public function test_capabilities_with_different_needs_and_no_ties_should_be_separate_services(): void
    {
        $search = new Capability('product-search', changesPerMonth: 20, peakRequestsPerSecond: 5_000);
        $returns = new Capability('returns-policy', changesPerMonth: 1, peakRequestsPerSecond: 5);

        $advice = (new GranularityAnalyzer)->evaluate($search, $returns);

        $this->assertSame(GranularityAdvice::SEPARATE, $advice->verdict);
        $this->assertCount(2, $advice->disintegrators, 'Volatility and scalability both pull them apart.');
        $this->assertSame([], $advice->integrators);
    }

    public function test_without_a_reason_to_split_they_stay_together(): void
    {
        $profile = new Capability('edit-profile');
        $avatar = new Capability('upload-avatar');

        $advice = (new GranularityAnalyzer)->evaluate($profile, $avatar);

        $this->assertSame(GranularityAdvice::TOGETHER, $advice->verdict, 'Every boundary has a cost; none is justified here.');
    }

    public function test_a_shared_transaction_outweighs_reasons_to_split(): void
    {
        $checkout = new Capability('place-order', changesPerMonth: 12, peakRequestsPerSecond: 800);
        $stock = new Capability('reserve-stock', changesPerMonth: 1, peakRequestsPerSecond: 20);

        $advice = (new GranularityAnalyzer)->evaluate($checkout, $stock, needAtomicTransaction: true);

        $this->assertCount(2, $advice->disintegrators, 'There ARE good reasons to split...');
        $this->assertSame(GranularityAdvice::TOGETHER, $advice->verdict, '...but an order without its stock reservation is not acceptable.');
        $this->assertStringContainsString('commit or roll back together', $advice->integrators[0]);
    }

    public function test_chatty_capabilities_belong_together(): void
    {
        $cart = new Capability('cart', mustStayUp: true);
        $pricing = new Capability('pricing-rules');

        $apart = (new GranularityAnalyzer)->evaluate($cart, $pricing, callsPerRequest: 1);
        $chatty = (new GranularityAnalyzer)->evaluate($cart, $pricing, callsPerRequest: 8);

        $this->assertSame(GranularityAdvice::SEPARATE, $apart->verdict);
        $this->assertSame(GranularityAdvice::TOGETHER, $chatty->verdict, 'Eight network calls per request would undo the fault-tolerance benefit.');
    }

    public function test_sensitive_data_is_a_reason_to_isolate(): void
    {
        $cards = new Capability('store-card-details', handlesSensitiveData: true);
        $wishlist = new Capability('wishlist');

        $advice = (new GranularityAnalyzer)->evaluate($cards, $wishlist);

        $this->assertSame(GranularityAdvice::SEPARATE, $advice->verdict);
        $this->assertStringContainsString('security', $advice->disintegrators[0]);
    }

    public function test_writing_the_same_table_keeps_capabilities_together(): void
    {
        $place = new Capability('place-order', changesPerMonth: 12, writesTables: ['orders', 'order_lines']);
        $cancel = new Capability('cancel-order', changesPerMonth: 1, writesTables: ['orders']);

        $advice = (new GranularityAnalyzer)->evaluate($place, $cancel);

        $this->assertSame(GranularityAdvice::TOGETHER, $advice->verdict);
        $this->assertSame(['shared data: both write to orders'], $advice->integrators);
    }

    // ---- Decomposition strategies -------------------------------------------------------

    /** @return list<Capability> */
    private function shop(): array
    {
        return [
            new Capability('place-order', writesTables: ['orders', 'order_lines'], businessArea: 'ordering'),
            new Capability('cancel-order', writesTables: ['orders'], businessArea: 'ordering'),
            new Capability('charge-card', writesTables: ['payments'], businessArea: 'payments'),
            new Capability('refund', writesTables: ['payments', 'refunds'], businessArea: 'payments'),
            new Capability('print-label', writesTables: ['shipments'], businessArea: 'shipping'),
        ];
    }

    public function test_decomposing_by_business_capability(): void
    {
        $this->assertSame([
            'ordering' => ['place-order', 'cancel-order'],
            'payments' => ['charge-card', 'refund'],
            'shipping' => ['print-label'],
        ], (new DecompositionPlanner)->byBusinessCapability($this->shop()));
    }

    public function test_decomposing_by_data_ownership_gives_every_table_a_single_owner(): void
    {
        $this->assertSame([
            ['place-order', 'cancel-order'],
            ['charge-card', 'refund'],
            ['print-label'],
        ], (new DecompositionPlanner)->byDataOwnership($this->shop()));
    }

    public function test_data_ownership_is_transitive(): void
    {
        $services = (new DecompositionPlanner)->byDataOwnership([
            new Capability('a', writesTables: ['t1']),
            new Capability('b', writesTables: ['t1', 't2']),
            new Capability('c', writesTables: ['t2']),
            new Capability('d', writesTables: ['t3']),
        ]);

        $this->assertSame([['a', 'b', 'c'], ['d']], $services, 'a and c share nothing directly, but b ties them together.');
    }

    public function test_the_data_check_exposes_a_boundary_drawn_in_the_wrong_place(): void
    {
        // On paper "loyalty" is its own business area, but it writes to the orders table.
        $capabilities = [
            ...$this->shop(),
            new Capability('award-points', writesTables: ['orders', 'points'], businessArea: 'loyalty'),
        ];

        $byBusiness = (new DecompositionPlanner)->byBusinessCapability($capabilities);
        $byData = (new DecompositionPlanner)->byDataOwnership($capabilities);

        $this->assertArrayHasKey('loyalty', $byBusiness);
        $this->assertContains(
            ['place-order', 'cancel-order', 'award-points'],
            $byData,
            'Loyalty cannot be an independent service until it stops writing ordering\'s data.'
        );
    }
}
