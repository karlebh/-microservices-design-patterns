<?php

declare(strict_types=1);

namespace Patterns\Decomposition\DomainDrivenDesign\Shipping;

use DomainException;
use Patterns\Decomposition\DomainDrivenDesign\Sales\OrderPlaced;

/**
 * The anti-corruption layer between Sales and Shipping.
 *
 * It is the ONLY place in the Shipping context that knows what a Sales
 * event looks like. It translates:
 *
 *   order            -> shipment
 *   customer         -> recipient
 *   line (sku, qty)  -> one parcel per unit, weighed from Shipping's own catalogue
 *
 * If Sales renames a field tomorrow, this class changes and the Shipping
 * model does not. Without it, Sales' concepts (prices, totals, lines) would
 * leak into Shipping and the two models would slowly merge into one.
 */
final class SalesTranslator
{
    /** @param array<string, int> $weightsBySku Shipping's own knowledge about products: sku => grams */
    public function __construct(private readonly array $weightsBySku) {}

    public function shipmentFor(OrderPlaced $event): Shipment
    {
        $shipment = new Shipment(
            id: 'shipment-for-'.$event->orderId,
            recipientId: $event->customerId,
            address: $event->deliveryAddress,
        );

        foreach ($event->lines as $line) {
            $weight = $this->weightsBySku[$line['sku']]
                ?? throw new DomainException("Shipping does not know the weight of [{$line['sku']}].");

            for ($unit = 0; $unit < $line['quantity']; $unit++) {
                $shipment->addParcel($line['sku'], $weight);
            }
        }

        return $shipment;
    }
}
