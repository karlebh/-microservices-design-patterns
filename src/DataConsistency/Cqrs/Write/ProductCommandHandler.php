<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Write;

use Closure;
use DomainException;
use Patterns\DataConsistency\Cqrs\CommandBus;
use Patterns\DataConsistency\Cqrs\Commands\AdjustStock;
use Patterns\DataConsistency\Cqrs\Commands\ChangePrice;
use Patterns\DataConsistency\Cqrs\Commands\CreateProduct;

/**
 * The write side.
 *
 * Its model is small and normalized, and exists for one purpose: deciding
 * whether a change is allowed. It has no "list", "search" or "format"
 * methods — nothing here is designed for reading.
 *
 * Every accepted change is announced as an event, which is how the read
 * side finds out.
 */
final class ProductCommandHandler
{
    /** @var array<string, array{name: string, price_cents: int, stock: int}> the write model's "table" */
    private array $products = [];

    /** @var list<array{type: string, sku: string, data: array}> everything that ever happened */
    private array $events = [];

    /** @param Closure(array): void $publish receives each change event */
    public function __construct(private readonly Closure $publish) {}

    public function registerOn(CommandBus $bus): void
    {
        $bus->register(CreateProduct::class, $this->create(...));
        $bus->register(ChangePrice::class, $this->changePrice(...));
        $bus->register(AdjustStock::class, $this->adjustStock(...));
    }

    private function create(CreateProduct $command): void
    {
        if (isset($this->products[$command->sku])) {
            throw new DomainException("SKU [{$command->sku}] already exists.");
        }

        $this->assertValidPrice($command->priceCents);

        $this->products[$command->sku] = ['name' => $command->name, 'price_cents' => $command->priceCents, 'stock' => 0];

        $this->emit('product.created', $command->sku, ['name' => $command->name, 'price_cents' => $command->priceCents]);
    }

    private function changePrice(ChangePrice $command): void
    {
        $this->assertExists($command->sku);
        $this->assertValidPrice($command->priceCents);

        $this->products[$command->sku]['price_cents'] = $command->priceCents;

        $this->emit('product.price_changed', $command->sku, ['price_cents' => $command->priceCents]);
    }

    private function adjustStock(AdjustStock $command): void
    {
        $this->assertExists($command->sku);

        $newStock = $this->products[$command->sku]['stock'] + $command->delta;

        if ($newStock < 0) {
            throw new DomainException('Stock cannot go below zero.');
        }

        $this->products[$command->sku]['stock'] = $newStock;

        $this->emit('product.stock_changed', $command->sku, ['stock' => $newStock]);
    }

    /** @return list<array{type: string, sku: string, data: array}> for rebuilding read models */
    public function events(): array
    {
        return $this->events;
    }

    private function emit(string $type, string $sku, array $data): void
    {
        $event = ['type' => $type, 'sku' => $sku, 'data' => $data];

        $this->events[] = $event;
        ($this->publish)($event);
    }

    private function assertExists(string $sku): void
    {
        if (! isset($this->products[$sku])) {
            throw new DomainException("Unknown SKU [{$sku}].");
        }
    }

    private function assertValidPrice(int $priceCents): void
    {
        if ($priceCents <= 0) {
            throw new DomainException('Price must be positive.');
        }
    }
}
