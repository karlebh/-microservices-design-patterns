<?php

declare(strict_types=1);

namespace Patterns\DataConsistency\Cqrs\Read;

/**
 * Keeps the read model in sync with the write side by translating change
 * events into denormalized rows.
 *
 * - sync:  the read model is updated in the same call as the write
 * - async: events wait in a queue until catchUp() runs (a worker, in real
 *          life), so the read side is briefly behind — eventual consistency
 */
final class ProductProjector
{
    private const LOW_STOCK_THRESHOLD = 5;

    /** @var list<array{type: string, sku: string, data: array}> */
    private array $queue = [];

    public function __construct(
        private readonly ProductReadModel $readModel,
        private readonly bool $async = false,
    ) {}

    public function receive(array $event): void
    {
        if ($this->async) {
            $this->queue[] = $event;

            return;
        }

        $this->project($event);
    }

    /** Async mode: process everything that queued up. */
    public function catchUp(): void
    {
        while ($event = array_shift($this->queue)) {
            $this->project($event);
        }
    }

    public function pending(): int
    {
        return count($this->queue);
    }

    /**
     * Throw the read model away and rebuild it from the full event history.
     *
     * @param  list<array{type: string, sku: string, data: array}>  $events
     */
    public function rebuild(array $events): void
    {
        $this->readModel->clear();
        $this->queue = [];

        foreach ($events as $event) {
            $this->project($event);
        }
    }

    private function project(array $event): void
    {
        $changes = match ($event['type']) {
            'product.created' => [
                'name' => $event['data']['name'],
                'price' => $this->formatPrice($event['data']['price_cents']),
            ],
            'product.price_changed' => [
                'price' => $this->formatPrice($event['data']['price_cents']),
            ],
            'product.stock_changed' => [
                'stock' => $event['data']['stock'],
                'availability' => match (true) {
                    $event['data']['stock'] === 0 => 'Sold out',
                    $event['data']['stock'] <= self::LOW_STOCK_THRESHOLD => 'Low stock',
                    default => 'In stock',
                },
            ],
        };

        $this->readModel->upsert($event['sku'], $changes);
    }

    private function formatPrice(int $cents): string
    {
        return '$'.number_format($cents / 100, 2);
    }
}
