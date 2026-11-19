<?php

declare(strict_types=1);

namespace Tests\Patterns\Messaging;

use InvalidArgumentException;
use Patterns\Messaging\EventDrivenBestPractices\EventEnvelope;
use Patterns\Messaging\EventDrivenBestPractices\SchemaRegistry;
use Patterns\Messaging\EventDrivenBestPractices\Upcaster;
use PHPUnit\Framework\TestCase;

final class EventDrivenBestPracticesTest extends TestCase
{
    // ---- Envelope: ids, correlation, causation ------------------------------------------

    public function test_every_event_gets_a_unique_id_and_a_timestamp(): void
    {
        $a = EventEnvelope::start('order.placed', 'orders', ['order_id' => 1]);
        $b = EventEnvelope::start('order.placed', 'orders', ['order_id' => 1]);

        $this->assertNotSame($a->id, $b->id, 'Same content, still two distinct events.');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $a->occurredAt);
    }

    public function test_the_correlation_id_ties_a_whole_flow_together_while_causation_links_parent_to_child(): void
    {
        // orders -> payments -> shipping, each reacting to the previous event.
        $placed = EventEnvelope::start('order.placed', 'orders', ['order_id' => 1]);
        $paid = $placed->causes('payment.captured', 'payments', ['order_id' => 1]);
        $shipped = $paid->causes('shipment.created', 'shipping', ['order_id' => 1]);

        // One id finds every event of this checkout, across all services.
        $this->assertSame($placed->correlationId, $paid->correlationId);
        $this->assertSame($placed->correlationId, $shipped->correlationId);

        // Causation reconstructs who triggered whom.
        $this->assertNull($placed->causationId, 'The first event has no parent.');
        $this->assertSame($placed->id, $paid->causationId);
        $this->assertSame($paid->id, $shipped->causationId);
    }

    public function test_separate_flows_have_separate_correlation_ids(): void
    {
        $one = EventEnvelope::start('order.placed', 'orders', ['order_id' => 1]);
        $two = EventEnvelope::start('order.placed', 'orders', ['order_id' => 2]);

        $this->assertNotSame($one->correlationId, $two->correlationId);
    }

    // ---- Upcasting: handlers only ever see the latest version ---------------------------

    private function upcaster(): Upcaster
    {
        return (new Upcaster)
            // v1 -> v2: `name` was split into first and last name.
            ->register('customer.registered', 1, function (array $data) {
                [$first, $last] = explode(' ', $data['name'], 2) + [1 => ''];
                unset($data['name']);

                return $data + ['first_name' => $first, 'last_name' => $last];
            })
            // v2 -> v3: a marketing consent flag was added; old events default to "no".
            ->register('customer.registered', 2, fn (array $data) => $data + ['marketing_consent' => false]);
    }

    public function test_an_old_event_is_upgraded_step_by_step_to_the_latest_version(): void
    {
        $old = EventEnvelope::start('customer.registered', 'customers', ['id' => 7, 'name' => 'Ada Lovelace'], version: 1);

        $current = $this->upcaster()->upcast($old);

        $this->assertSame(3, $current->version);
        $this->assertSame(
            ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'marketing_consent' => false],
            $current->data,
        );
        $this->assertSame($old->id, $current->id, 'It is still the same event, only presented in the new shape.');
        $this->assertSame(['id' => 7, 'name' => 'Ada Lovelace'], $old->data, 'The stored event is untouched.');
    }

    public function test_events_enter_the_chain_at_their_own_version(): void
    {
        $upcaster = $this->upcaster();

        $v2 = EventEnvelope::start('customer.registered', 'customers', ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Lovelace'], version: 2);
        $v3 = EventEnvelope::start('customer.registered', 'customers', ['id' => 7, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'marketing_consent' => true], version: 3);

        $this->assertFalse($upcaster->upcast($v2)->data['marketing_consent'], 'Only the v2 -> v3 step ran.');
        $this->assertSame($v3, $upcaster->upcast($v3), 'Already current: returned as is.');
        $this->assertSame(3, $upcaster->latestVersion('customer.registered'));
    }

    // ---- Schema registry: stop breaking changes before they ship ------------------------

    private const ORDER_PLACED_V1 = [
        'order_id' => ['type' => 'int', 'required' => true],
        'total_cents' => ['type' => 'int', 'required' => true],
        'note' => ['type' => 'string', 'required' => false],
    ];

    public function test_adding_an_optional_field_is_the_safe_way_to_evolve(): void
    {
        $registry = new SchemaRegistry;

        $this->assertSame(1, $registry->register('order.placed', self::ORDER_PLACED_V1));
        $this->assertSame(2, $registry->register('order.placed', self::ORDER_PLACED_V1 + [
            'coupon' => ['type' => 'string', 'required' => false],
        ]));
    }

    public function test_breaking_changes_are_rejected_with_a_reason(): void
    {
        $registry = new SchemaRegistry;
        $registry->register('order.placed', self::ORDER_PLACED_V1);

        $removed = self::ORDER_PLACED_V1;
        unset($removed['total_cents']);

        $retyped = self::ORDER_PLACED_V1;
        $retyped['total_cents']['type'] = 'string';

        $newRequired = self::ORDER_PLACED_V1 + ['currency' => ['type' => 'string', 'required' => true]];

        $this->assertStringContainsString('removed', $registry->incompatibilities('order.placed', $removed)[0]);
        $this->assertStringContainsString('changed type', $registry->incompatibilities('order.placed', $retyped)[0]);
        $this->assertStringContainsString('older events do not have it', $registry->incompatibilities('order.placed', $newRequired)[0]);

        $this->expectException(InvalidArgumentException::class);
        $registry->register('order.placed', $newRequired);
    }

    public function test_dropping_an_optional_field_is_allowed(): void
    {
        $registry = new SchemaRegistry;
        $registry->register('order.placed', self::ORDER_PLACED_V1);

        $withoutNote = self::ORDER_PLACED_V1;
        unset($withoutNote['note']);

        $this->assertSame([], $registry->incompatibilities('order.placed', $withoutNote), 'Nobody was allowed to depend on it.');
    }

    public function test_payloads_are_validated_and_unknown_fields_are_tolerated(): void
    {
        $registry = new SchemaRegistry;
        $registry->register('order.placed', self::ORDER_PLACED_V1);

        $this->assertSame([], $registry->validate('order.placed', 1, [
            'order_id' => 1,
            'total_cents' => 4_999,
            'added_by_a_newer_producer' => 'ignored', // tolerant reader
        ]));

        $errors = $registry->validate('order.placed', 1, ['order_id' => '1']);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('[order_id] must be int', $errors[0]);
        $this->assertStringContainsString('Missing required field [total_cents]', $errors[1]);
    }
}
