<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use Patterns\DataConsistency\Saga\Choreography\EventBus;
use Patterns\DataConsistency\Saga\Choreography\InventoryService;
use Patterns\DataConsistency\Saga\Choreography\OrderService;
use Patterns\DataConsistency\Saga\Choreography\PaymentService;
use Patterns\DataConsistency\Saga\Orchestration\SagaOrchestrator;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SagaTest extends TestCase
{
    // ---- Orchestration ------------------------------------------------------------------

    /** Builds the "place order" saga over three fake services (plain arrays). */
    private function placeOrderSaga(array &$stock, array &$payments, array &$shipments, bool $cardDeclines = false): SagaOrchestrator
    {
        return (new SagaOrchestrator)
            ->step(
                'reserve-stock',
                function (array $ctx) use (&$stock) {
                    $stock[$ctx['sku']] -= $ctx['quantity'];
                },
                function (array $ctx) use (&$stock) {
                    $stock[$ctx['sku']] += $ctx['quantity'];
                },
            )
            ->step(
                'charge-payment',
                function (array $ctx) use (&$payments, $cardDeclines) {
                    if ($cardDeclines) {
                        throw new RuntimeException('card declined');
                    }

                    $payments[] = ['type' => 'charge', 'amount' => $ctx['amount']];

                    return ['payment_ref' => 'pay_1'];
                },
                function (array $ctx) use (&$payments) {
                    $payments[] = ['type' => 'refund', 'amount' => $ctx['amount']];
                },
            )
            ->step(
                'create-shipment',
                function (array $ctx) use (&$shipments) {
                    if ($ctx['address'] === 'unreachable') {
                        throw new RuntimeException('no carrier delivers there');
                    }

                    $shipments[] = $ctx['address'];
                },
            );
    }

    public function test_orchestrator_runs_every_step_when_all_succeed(): void
    {
        $stock = ['book' => 10];
        $payments = $shipments = [];

        $result = $this->placeOrderSaga($stock, $payments, $shipments)
            ->run(['sku' => 'book', 'quantity' => 2, 'amount' => 50, 'address' => 'Lagos']);

        $this->assertTrue($result->completed);
        $this->assertSame(8, $stock['book']);
        $this->assertSame('pay_1', $result->context['payment_ref'], 'Steps can pass data to later steps.');
        $this->assertSame(['reserve-stock: done', 'charge-payment: done', 'create-shipment: done'], $result->log);
    }

    public function test_orchestrator_compensates_completed_steps_in_reverse_order(): void
    {
        $stock = ['book' => 10];
        $payments = $shipments = [];

        // Stock and payment succeed, shipping fails.
        $result = $this->placeOrderSaga($stock, $payments, $shipments)
            ->run(['sku' => 'book', 'quantity' => 2, 'amount' => 50, 'address' => 'unreachable']);

        $this->assertFalse($result->completed);
        $this->assertSame('create-shipment', $result->failedStep);
        $this->assertSame([
            'reserve-stock: done',
            'charge-payment: done',
            'create-shipment: failed',
            'charge-payment: compensated',
            'reserve-stock: compensated',
        ], $result->log);

        $this->assertSame(10, $stock['book'], 'Stock is back.');
        $this->assertSame(['charge', 'refund'], array_column($payments, 'type'), 'The charge is not erased; it is offset by a refund.');
    }

    public function test_orchestrator_does_not_compensate_the_step_that_failed(): void
    {
        $stock = ['book' => 10];
        $payments = $shipments = [];

        $result = $this->placeOrderSaga($stock, $payments, $shipments, cardDeclines: true)
            ->run(['sku' => 'book', 'quantity' => 2, 'amount' => 50, 'address' => 'Lagos']);

        $this->assertSame(['reserve-stock: done', 'charge-payment: failed', 'reserve-stock: compensated'], $result->log);
        $this->assertSame([], $payments, 'Nothing was charged, so nothing is refunded.');
        $this->assertSame(10, $stock['book']);
    }

    // ---- Choreography -------------------------------------------------------------------

    /** @return array{EventBus, OrderService, InventoryService, PaymentService} */
    private function services(int $stock = 10, int $creditLimit = 1_000): array
    {
        $bus = new EventBus;

        return [
            $bus,
            new OrderService($bus),
            new InventoryService($bus, ['book' => $stock]),
            new PaymentService($bus, $creditLimit),
        ];
    }

    public function test_choreography_completes_through_a_chain_of_events(): void
    {
        [$bus, $orders, $inventory, $payments] = $this->services();

        $orders->placeOrder('order-1', 'book', 2, 50);

        $this->assertSame(['order.placed', 'inventory.reserved', 'payment.charged'], $bus->history);
        $this->assertSame('confirmed', $orders->orders['order-1']);
        $this->assertSame(8, $inventory->stock['book']);
        $this->assertSame(50, $payments->charges['order-1']);
    }

    public function test_choreography_compensates_when_payment_fails(): void
    {
        [$bus, $orders, $inventory, $payments] = $this->services(creditLimit: 10);

        $orders->placeOrder('order-1', 'book', 2, 50);

        $this->assertSame(
            ['order.placed', 'inventory.reserved', 'payment.failed', 'inventory.released'],
            $bus->history,
            'The failure event caused inventory to undo its own step.'
        );
        $this->assertSame('cancelled', $orders->orders['order-1']);
        $this->assertSame(10, $inventory->stock['book']);
        $this->assertSame([], $payments->charges);
    }

    public function test_choreography_stops_early_when_stock_is_missing(): void
    {
        [$bus, $orders, , $payments] = $this->services(stock: 1);

        $orders->placeOrder('order-1', 'book', 2, 50);

        $this->assertSame(['order.placed', 'inventory.rejected'], $bus->history, 'Payment was never asked.');
        $this->assertSame('cancelled', $orders->orders['order-1']);
        $this->assertSame([], $payments->charges);
    }

    public function test_compensation_is_idempotent_under_redelivery(): void
    {
        [$bus, $orders, $inventory] = $this->services(creditLimit: 10);

        $orders->placeOrder('order-1', 'book', 2, 50);

        // The broker redelivers the failure event.
        $bus->publish('payment.failed', ['order_id' => 'order-1']);

        $this->assertSame(10, $inventory->stock['book'], 'Stock was released once, not twice.');
    }
}
