<?php

declare(strict_types=1);

namespace Tests\Patterns\DataArchitecture;

use Patterns\DataArchitecture\MaterializedViews\MaterializedView;
use Patterns\DataArchitecture\MaterializedViews\Table;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class MaterializedViewsTest extends TestCase
{
    /** SELECT customer, COUNT(*), SUM(amount), ... FROM orders GROUP BY customer */
    private function revenuePerCustomer(?\Closure $where = null): MaterializedView
    {
        return new MaterializedView(
            groupBy: fn (array $order) => $order['customer'],
            value: fn (array $order) => $order['amount'],
            where: $where,
        );
    }

    private function ordersWith(MaterializedView $view): Table
    {
        $orders = new Table;
        $orders->maintain($view);

        return $orders;
    }

    // ---- Full refresh -------------------------------------------------------------------

    public function test_a_materialized_view_stores_the_query_result(): void
    {
        $view = $this->revenuePerCustomer();

        $view->fullRefresh([
            ['customer' => 'ada', 'amount' => 50],
            ['customer' => 'ada', 'amount' => 30],
            ['customer' => 'bob', 'amount' => 20],
        ]);

        $this->assertSame(['count' => 2, 'sum' => 80, 'avg' => 40, 'min' => 30, 'max' => 50], $view->get('ada'));
        $this->assertSame(['ada', 'bob'], array_keys($view->all()));
    }

    public function test_a_view_refreshed_on_a_schedule_is_stale_in_between(): void
    {
        $orders = new Table;
        $orders->insert(['customer' => 'ada', 'amount' => 50]);

        $nightly = $this->revenuePerCustomer();
        $nightly->fullRefresh($orders->rows());

        $orders->insert(['customer' => 'ada', 'amount' => 30]); // happens after the refresh

        $this->assertSame(50, $nightly->get('ada')['sum'], 'The view still shows yesterday.');

        $nightly->fullRefresh($orders->rows());
        $this->assertSame(80, $nightly->get('ada')['sum']);
    }

    // ---- Incremental maintenance --------------------------------------------------------

    public function test_inserts_adjust_only_the_affected_group(): void
    {
        $view = $this->revenuePerCustomer();
        $orders = $this->ordersWith($view);

        $orders->insert(['customer' => 'ada', 'amount' => 50]);
        $orders->insert(['customer' => 'bob', 'amount' => 20]);
        $orders->insert(['customer' => 'ada', 'amount' => 30]);

        $this->assertSame(80, $view->get('ada')['sum'], 'Always current, with no refresh ever being run.');
        $this->assertSame(1, $view->get('bob')['count']);
    }

    public function test_a_delete_retracts_the_rows_contribution(): void
    {
        $view = $this->revenuePerCustomer();
        $orders = $this->ordersWith($view);

        $orders->insert(['customer' => 'ada', 'amount' => 50]);
        $refunded = $orders->insert(['customer' => 'ada', 'amount' => 30]);

        $orders->delete($refunded);

        $this->assertSame(['count' => 1, 'sum' => 50, 'avg' => 50, 'min' => 50, 'max' => 50], $view->get('ada'));
    }

    public function test_a_group_disappears_with_its_last_row(): void
    {
        $view = $this->revenuePerCustomer();
        $orders = $this->ordersWith($view);

        $id = $orders->insert(['customer' => 'bob', 'amount' => 20]);
        $orders->delete($id);

        $this->assertNull($view->get('bob'));
        $this->assertSame([], $view->all());
    }

    public function test_an_update_is_a_retraction_followed_by_an_insertion(): void
    {
        $view = $this->revenuePerCustomer();
        $orders = $this->ordersWith($view);

        $id = $orders->insert(['customer' => 'ada', 'amount' => 50]);
        $orders->insert(['customer' => 'bob', 'amount' => 20]);

        $orders->update($id, ['amount' => 75]);
        $this->assertSame(75, $view->get('ada')['sum']);

        // The order is reassigned: it leaves one group and joins another.
        $orders->update($id, ['customer' => 'bob']);

        $this->assertNull($view->get('ada'));
        $this->assertSame(['count' => 2, 'sum' => 95, 'avg' => 47.5, 'min' => 20, 'max' => 75], $view->get('bob'));
    }

    public function test_min_and_max_survive_the_deletion_of_the_current_extreme(): void
    {
        $view = $this->revenuePerCustomer();
        $orders = $this->ordersWith($view);

        $smallest = $orders->insert(['customer' => 'ada', 'amount' => 5]);
        $orders->insert(['customer' => 'ada', 'amount' => 40]);
        $orders->insert(['customer' => 'ada', 'amount' => 40]);
        $largest = $orders->insert(['customer' => 'ada', 'amount' => 90]);

        $orders->delete($smallest);
        $orders->delete($largest);

        $this->assertSame(40, $view->get('ada')['min'], 'MIN cannot be "subtracted"; the view kept enough state to find the next one.');
        $this->assertSame(40, $view->get('ada')['max']);
    }

    public function test_rows_can_move_in_and_out_of_a_filtered_view(): void
    {
        // ... WHERE status = 'paid' GROUP BY customer
        $paidRevenue = $this->revenuePerCustomer(where: fn (array $order) => $order['status'] === 'paid');
        $orders = $this->ordersWith($paidRevenue);

        $id = $orders->insert(['customer' => 'ada', 'amount' => 50, 'status' => 'pending']);
        $this->assertNull($paidRevenue->get('ada'), 'A pending order is not in the view.');

        $orders->update($id, ['status' => 'paid']);
        $this->assertSame(50, $paidRevenue->get('ada')['sum'], 'Paying it makes it appear.');

        $orders->update($id, ['status' => 'refunded']);
        $this->assertNull($paidRevenue->get('ada'), 'Refunding it makes it vanish again.');
    }

    // ---- Why bother ---------------------------------------------------------------------

    public function test_incremental_maintenance_costs_the_size_of_the_change_not_the_size_of_the_table(): void
    {
        $incremental = $this->revenuePerCustomer();
        $orders = $this->ordersWith($incremental);

        foreach (range(1, 5_000) as $i) {
            $orders->insert(['customer' => "customer-{$i}", 'amount' => 10]);
        }

        $scheduled = $this->revenuePerCustomer();

        // One more order arrives.
        $incremental->rowsProcessed = 0;
        $orders->insert(['customer' => 'customer-1', 'amount' => 99]);
        $scheduled->fullRefresh($orders->rows());

        $this->assertSame(1, $incremental->rowsProcessed, 'Incremental: touched the one new row.');
        $this->assertSame(5_001, $scheduled->rowsProcessed, 'Full refresh: re-read the entire table for the same result.');
        $this->assertSame($scheduled->get('customer-1'), $incremental->get('customer-1'));
    }

    public function test_incremental_and_full_computation_always_agree(): void
    {
        $random = new Randomizer(new Mt19937(17));
        $incremental = $this->revenuePerCustomer(where: fn (array $order) => $order['amount'] >= 10);
        $orders = $this->ordersWith($incremental);

        for ($step = 0; $step < 400; $step++) {
            $ids = $orders->ids();
            $operation = $ids === [] ? 0 : $random->getInt(0, 2);

            match ($operation) {
                0 => $orders->insert(['customer' => 'c'.$random->getInt(1, 6), 'amount' => $random->getInt(1, 60)]),
                1 => $orders->update($ids[$random->getInt(0, count($ids) - 1)], [
                    'customer' => 'c'.$random->getInt(1, 6),
                    'amount' => $random->getInt(1, 60),
                ]),
                2 => $orders->delete($ids[$random->getInt(0, count($ids) - 1)]),
            };

            // Recompute the same query from scratch and compare.
            $fromScratch = $this->revenuePerCustomer(where: fn (array $order) => $order['amount'] >= 10);
            $fromScratch->fullRefresh($orders->rows());

            $this->assertEquals($fromScratch->all(), $incremental->all(), "Diverged at step {$step}.");
        }
    }
}
