<?php

declare(strict_types=1);

namespace Tests\Patterns\DataConsistency;

use DomainException;
use Patterns\DataConsistency\Cqrs\CommandBus;
use Patterns\DataConsistency\Cqrs\Commands\AdjustStock;
use Patterns\DataConsistency\Cqrs\Commands\ChangePrice;
use Patterns\DataConsistency\Cqrs\Commands\CreateProduct;
use Patterns\DataConsistency\Cqrs\Read\ProductProjector;
use Patterns\DataConsistency\Cqrs\Read\ProductReadModel;
use Patterns\DataConsistency\Cqrs\Write\ProductCommandHandler;
use PHPUnit\Framework\TestCase;

final class CqrsTest extends TestCase
{
    /** @return array{CommandBus, ProductReadModel, ProductProjector, ProductCommandHandler} */
    private function system(bool $async = false): array
    {
        $readModel = new ProductReadModel;
        $projector = new ProductProjector($readModel, $async);
        $handler = new ProductCommandHandler($projector->receive(...));

        $bus = new CommandBus;
        $handler->registerOn($bus);

        return [$bus, $readModel, $projector, $handler];
    }

    public function test_commands_change_state_and_queries_read_a_model_shaped_for_the_screen(): void
    {
        [$bus, $readModel] = $this->system();

        $bus->dispatch(new CreateProduct('book-1', 'Designing Data-Intensive Applications', 4_599));
        $bus->dispatch(new AdjustStock('book-1', 3));

        $this->assertSame([
            'sku' => 'book-1',
            'name' => 'Designing Data-Intensive Applications',
            'price' => '$45.99',          // formatted once, at write time
            'stock' => 3,
            'availability' => 'Low stock', // derived once, at write time
        ], $readModel->find('book-1'));
    }

    public function test_the_write_side_enforces_the_business_rules(): void
    {
        [$bus, $readModel] = $this->system();
        $bus->dispatch(new CreateProduct('book-1', 'DDIA', 4_599));
        $bus->dispatch(new AdjustStock('book-1', 2));

        $rejected = 0;

        foreach ([
            new CreateProduct('book-1', 'Duplicate', 1_000), // SKU must be unique
            new ChangePrice('book-1', 0),                     // price must be positive
            new AdjustStock('book-1', -5),                    // stock can't go negative
            new AdjustStock('ghost', 1),                      // product must exist
        ] as $command) {
            try {
                $bus->dispatch($command);
            } catch (DomainException) {
                $rejected++;
            }
        }

        $this->assertSame(4, $rejected);
        $this->assertSame(2, $readModel->find('book-1')['stock'], 'Rejected commands never reach the read side.');
    }

    public function test_queries_answer_different_questions_from_the_same_events(): void
    {
        [$bus, $readModel] = $this->system();

        $bus->dispatch(new CreateProduct('a', 'Plenty', 100));
        $bus->dispatch(new CreateProduct('b', 'Few', 100));
        $bus->dispatch(new CreateProduct('c', 'None', 100));
        $bus->dispatch(new AdjustStock('a', 50));
        $bus->dispatch(new AdjustStock('b', 2));

        $this->assertSame(['a', 'b'], $readModel->available());
        $this->assertSame(['b'], $readModel->lowStock());
    }

    public function test_an_async_read_side_is_eventually_consistent(): void
    {
        [$bus, $readModel, $projector] = $this->system(async: true);

        $bus->dispatch(new CreateProduct('book-1', 'DDIA', 4_599));
        $bus->dispatch(new ChangePrice('book-1', 3_999));

        // The write succeeded, but the query side hasn't heard about it yet.
        $this->assertNull($readModel->find('book-1'));
        $this->assertSame(2, $projector->pending());

        $projector->catchUp();

        $this->assertSame('$39.99', $readModel->find('book-1')['price']);
        $this->assertSame(0, $projector->pending());
    }

    public function test_a_read_model_can_be_rebuilt_from_the_event_history(): void
    {
        [$bus, $readModel, $projector, $handler] = $this->system();

        $bus->dispatch(new CreateProduct('book-1', 'DDIA', 4_599));
        $bus->dispatch(new AdjustStock('book-1', 20));
        $bus->dispatch(new ChangePrice('book-1', 3_999));

        $before = $readModel->find('book-1');

        // The read database is lost (or its schema changed)...
        $readModel->clear();
        $this->assertNull($readModel->find('book-1'));

        // ...so it is regenerated from the events. Nothing was lost.
        $projector->rebuild($handler->events());

        $this->assertSame($before, $readModel->find('book-1'));
    }
}
