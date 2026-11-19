<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use Patterns\Decomposition\ApiComposition\OrderDetailsComposer;
use Patterns\Decomposition\ApiComposition\Services\CustomerService;
use Patterns\Decomposition\ApiComposition\Services\OrderService;
use Patterns\Decomposition\ApiComposition\Services\ProductService;
use PHPUnit\Framework\TestCase;

final class ApiCompositionTest extends TestCase
{
    private OrderService $orders;

    private CustomerService $customers;

    private ProductService $products;

    private OrderDetailsComposer $composer;

    protected function setUp(): void
    {
        $this->orders = new OrderService;
        $this->customers = new CustomerService;
        $this->products = new ProductService;
        $this->composer = new OrderDetailsComposer($this->orders, $this->customers, $this->products);
    }

    public function test_each_service_knows_only_its_own_data(): void
    {
        $order = $this->orders->find(42);

        $this->assertSame(7, $order['customer_id'], 'An order points at a customer by id...');
        $this->assertArrayNotHasKey('customer_name', $order, '...and holds none of the customer\'s data.');
        $this->assertSame(['sku', 'quantity'], array_keys($order['lines'][0]), 'Likewise for products: just the reference.');
    }

    public function test_the_composer_joins_data_from_three_services_in_memory(): void
    {
        $details = $this->composer->orderDetails(42);

        $this->assertSame('Ada Lovelace', $details['customer']['name']);                               // from the customer service
        $this->assertSame('Designing Data-Intensive Applications', $details['lines'][0]['title']);     // from the product service
        $this->assertSame(2, $details['lines'][0]['quantity']);                                        // from the order service
        $this->assertSame(2 * 4_500 + 5 * 250 + 3_000, $details['total_cents']);                       // computed across both
        $this->assertTrue($details['complete']);
    }

    public function test_one_batch_call_replaces_a_call_per_row(): void
    {
        $this->composer->orderDetails(42);
        $this->assertSame(1, $this->products->calls, 'Three order lines, one request for all their products.');

        $this->products->calls = 0;

        $this->composer->orderDetailsWithOneCallPerLine(42);
        $this->assertSame(3, $this->products->calls, 'The N+1 version: one network round trip per line.');
    }

    public function test_the_whole_composition_costs_one_call_per_service(): void
    {
        $this->composer->orderDetails(42);

        $this->assertSame([1, 1, 1], [$this->orders->calls, $this->customers->calls, $this->products->calls]);
    }

    public function test_a_failing_service_degrades_the_result_instead_of_breaking_it(): void
    {
        $this->customers->down = true;

        $details = $this->composer->orderDetails(42);

        $this->assertNull($details['customer']);
        $this->assertFalse($details['complete'], 'The client can show "customer details unavailable".');
        $this->assertCount(3, $details['lines'], 'Everything that could be fetched is still returned.');
    }

    public function test_an_unknown_order_is_reported_without_calling_the_other_services(): void
    {
        $this->assertNull($this->composer->orderDetails(999));

        $this->assertSame(0, $this->customers->calls);
        $this->assertSame(0, $this->products->calls);
    }
}
