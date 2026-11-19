<?php

declare(strict_types=1);

namespace Tests\Patterns\Decomposition;

use DomainException;
use InvalidArgumentException;
use Patterns\Decomposition\DomainDrivenDesign\Sales\Money;
use Patterns\Decomposition\DomainDrivenDesign\Sales\Order;
use Patterns\Decomposition\DomainDrivenDesign\Sales\OrderPlaced;
use Patterns\Decomposition\DomainDrivenDesign\Sales\OrderRepository;
use Patterns\Decomposition\DomainDrivenDesign\Shipping\SalesTranslator;
use Patterns\Decomposition\DomainDrivenDesign\Shipping\Shipment;
use PHPUnit\Framework\TestCase;

final class DomainDrivenDesignTest extends TestCase
{
    // ---- Value objects ------------------------------------------------------------------

    public function test_value_objects_are_equal_by_value_and_never_change(): void
    {
        $price = new Money(1_000, 'USD');
        $sum = $price->add(new Money(250, 'USD'));

        $this->assertTrue($sum->equals(new Money(1_250, 'USD')), 'Two separate objects, same value: equal.');
        $this->assertSame(1_000, $price->cents, 'add() returned a new object and left the original alone.');
    }

    public function test_value_objects_carry_their_own_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Money(1_000, 'USD'))->add(new Money(1_000, 'EUR'));
    }

    // ---- Aggregate ----------------------------------------------------------------------

    private function orderWithABook(): Order
    {
        $order = new Order('order-1', 'customer-7');
        $order->addItem('book', new Money(4_500, 'USD'), 2);

        return $order;
    }

    public function test_the_total_is_always_consistent_with_the_lines(): void
    {
        $order = $this->orderWithABook();
        $order->addItem('pen', new Money(250, 'USD'), 4);

        $this->assertTrue($order->total()->equals(new Money(10_000, 'USD')));

        $order->removeItem('pen');
        $this->assertTrue($order->total()->equals(new Money(9_000, 'USD')));
    }

    public function test_the_root_enforces_one_line_per_product(): void
    {
        $order = $this->orderWithABook();
        $order->addItem('book', new Money(4_500, 'USD'), 1);

        $this->assertSame(3, $order->quantityOf('book'), 'Merged into the existing line instead of duplicating it.');
    }

    public function test_an_empty_order_cannot_be_placed(): void
    {
        $this->expectException(DomainException::class);

        (new Order('order-1', 'customer-7'))->place('1 Main St');
    }

    public function test_a_placed_order_is_frozen(): void
    {
        $order = $this->orderWithABook();
        $order->place('1 Main St');

        $this->expectException(DomainException::class);
        $order->addItem('pen', new Money(250, 'USD'), 1);
    }

    public function test_invalid_quantities_are_rejected_by_the_aggregate_itself(): void
    {
        $this->expectException(DomainException::class);

        $this->orderWithABook()->addItem('pen', new Money(250, 'USD'), 0);
    }

    // ---- Domain events and repository ---------------------------------------------------

    public function test_placing_an_order_raises_a_domain_event_that_is_published_on_save(): void
    {
        $orders = new OrderRepository;
        $order = $this->orderWithABook();

        $orders->save($order);
        $this->assertSame([], $orders->publishedEvents, 'Nothing noteworthy has happened yet.');

        $order->place('1 Main St');
        $orders->save($order);

        $event = $orders->publishedEvents[0];

        $this->assertInstanceOf(OrderPlaced::class, $event);
        $this->assertSame([['sku' => 'book', 'quantity' => 2]], $event->lines);
        $this->assertSame(9_000, $event->totalCents);

        $orders->save($order);
        $this->assertCount(1, $orders->publishedEvents, 'An event is published once, not on every save.');
    }

    public function test_the_repository_stores_and_returns_whole_aggregates(): void
    {
        $orders = new OrderRepository;
        $orders->save($this->orderWithABook());

        $loaded = $orders->get('order-1');

        $this->assertSame(2, $loaded->quantityOf('book'));
        $this->assertFalse($loaded->isPlaced());
    }

    // ---- Bounded contexts and the anti-corruption layer ---------------------------------

    public function test_shipping_builds_its_own_model_from_the_sales_event(): void
    {
        $order = $this->orderWithABook();
        $order->addItem('pen', new Money(250, 'USD'), 1);
        $order->place('1 Main St');

        [$orderPlaced] = $order->pullEvents();

        // Shipping knows weights; it neither knows nor cares about prices.
        $translator = new SalesTranslator(['book' => 800, 'pen' => 20]);
        $shipment = $translator->shipmentFor($orderPlaced);

        $this->assertSame('customer-7', $shipment->recipientId, 'Sales\' "customer" is Shipping\'s "recipient".');
        $this->assertSame(3, $shipment->parcelCount(), 'Two books and one pen: three parcels.');
        $this->assertSame(1_620, $shipment->totalWeightGrams());
        $this->assertSame('1 Main St', $shipment->address);
    }

    public function test_each_context_enforces_its_own_invariants(): void
    {
        $shipment = new Shipment('s-1', 'customer-7', '1 Main St');
        $shipment->addParcel('anvil', 25_000);

        try {
            $shipment->addParcel('anvil', 25_000);
            $this->fail('A 50 kg shipment should be refused.');
        } catch (DomainException) {
            // A rule that Sales has never heard of.
        }

        $shipment->dispatch();
        $this->assertTrue($shipment->isDispatched());

        $this->expectException(DomainException::class);
        $shipment->addParcel('feather', 1);
    }

    public function test_the_translator_fails_loudly_on_products_shipping_does_not_know(): void
    {
        $order = $this->orderWithABook();
        $order->place('1 Main St');
        [$orderPlaced] = $order->pullEvents();

        $this->expectException(DomainException::class);
        (new SalesTranslator([]))->shipmentFor($orderPlaced);
    }
}
