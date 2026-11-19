<?php

declare(strict_types=1);

namespace Tests\Patterns\BigData;

use InvalidArgumentException;
use Patterns\BigData\FeatureStore\FeatureStore;
use PHPUnit\Framework\TestCase;

final class FeatureStoreTest extends TestCase
{
    /**
     * A churn model. Timestamps are days.
     *
     *   day 10: ada has placed 5 orders in the last 30 days
     *   day 20: ada is down to 1 order           <- she is drifting away
     *   day 25: we label "did ada churn?" = yes
     *   day 40: ada has 0 orders                 <- only knowable AFTER the label
     */
    private function store(): FeatureStore
    {
        $store = new FeatureStore;
        $store->register('orders_last_30d');
        $store->register('support_tickets', ttl: 7);

        $store->ingest('orders_last_30d', 'ada', 10, 5);
        $store->ingest('orders_last_30d', 'ada', 20, 1);
        $store->ingest('orders_last_30d', 'ada', 40, 0);

        $store->ingest('orders_last_30d', 'bob', 12, 8);

        $store->ingest('support_tickets', 'ada', 10, 2);

        return $store;
    }

    // ---- Training -----------------------------------------------------------------------

    public function test_a_point_in_time_join_gives_the_value_that_was_known_at_the_time_of_the_example(): void
    {
        $training = $this->store()->getHistoricalFeatures(
            [
                ['entity' => 'ada', 'time' => 15, 'churned' => false],
                ['entity' => 'ada', 'time' => 25, 'churned' => true],
            ],
            ['orders_last_30d'],
        );

        $this->assertSame(5, $training[0]['orders_last_30d'], 'On day 15 the newest known value was the one from day 10.');
        $this->assertSame(1, $training[1]['orders_last_30d'], 'On day 25 it was the one from day 20 — not the one from day 40.');
        $this->assertTrue($training[1]['churned'], 'Labels and other columns pass through untouched.');
    }

    public function test_a_naive_latest_value_join_leaks_the_future_into_training(): void
    {
        $store = $this->store();
        $example = [['entity' => 'ada', 'time' => 25, 'churned' => true]];

        $leaky = $store->naiveLatestJoin($example, ['orders_last_30d']);
        $correct = $store->getHistoricalFeatures($example, ['orders_last_30d']);

        $this->assertSame(0, $leaky[0]['orders_last_30d'], 'The value from day 40: the model is shown the outcome it is supposed to predict.');
        $this->assertSame(1, $correct[0]['orders_last_30d']);
    }

    public function test_an_example_older_than_any_feature_value_gets_null(): void
    {
        $training = $this->store()->getHistoricalFeatures([['entity' => 'ada', 'time' => 5]], ['orders_last_30d']);

        $this->assertNull($training[0]['orders_last_30d'], 'Nothing was known yet. Inventing a value would be leakage too.');
    }

    public function test_feature_values_expire_after_their_ttl(): void
    {
        $store = $this->store();

        $recent = $store->getHistoricalFeatures([['entity' => 'ada', 'time' => 15]], ['support_tickets']);
        $old = $store->getHistoricalFeatures([['entity' => 'ada', 'time' => 30]], ['support_tickets']);

        $this->assertSame(2, $recent[0]['support_tickets'], 'Five days old: within the 7-day TTL.');
        $this->assertNull($old[0]['support_tickets'], 'Twenty days old: too stale to mean anything.');
    }

    // ---- Serving ------------------------------------------------------------------------

    public function test_the_online_store_serves_the_latest_materialized_values(): void
    {
        $store = $this->store();

        $this->assertSame(['orders_last_30d' => null], $store->getOnlineFeatures('ada', ['orders_last_30d'], now: 21), 'Nothing is served until it has been materialized.');

        $store->materialize(upTo: 21);

        $this->assertSame(['orders_last_30d' => 1], $store->getOnlineFeatures('ada', ['orders_last_30d'], now: 21));
        $this->assertSame(['orders_last_30d' => 8], $store->getOnlineFeatures('bob', ['orders_last_30d'], now: 21));
    }

    public function test_the_online_store_refuses_to_serve_expired_values(): void
    {
        $store = $this->store();
        $store->materialize(upTo: 12);

        $this->assertSame(2, $store->getOnlineFeatures('ada', ['support_tickets'], now: 12)['support_tickets']);
        $this->assertNull($store->getOnlineFeatures('ada', ['support_tickets'], now: 30)['support_tickets'], 'A model fed "2 open tickets" from weeks ago would be confidently wrong.');
    }

    public function test_training_and_serving_see_the_same_feature_values(): void
    {
        $store = $this->store();
        $features = ['orders_last_30d', 'support_tickets'];

        foreach ([11, 15, 21, 30, 45] as $day) {
            // What the model would have been served on that day...
            $store->materialize(upTo: $day);
            $served = $store->getOnlineFeatures('ada', $features, now: $day);

            // ...and what a training set built later says about that day.
            $trained = $store->getHistoricalFeatures([['entity' => 'ada', 'time' => $day]], $features)[0];

            $this->assertSame($served['orders_last_30d'], $trained['orders_last_30d'], "Day {$day}: no training/serving skew.");
            $this->assertSame($served['support_tickets'], $trained['support_tickets']);
        }
    }

    // ---- Registry -----------------------------------------------------------------------

    public function test_features_must_be_defined_before_they_are_used(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->store()->getOnlineFeatures('ada', ['a_feature_someone_made_up'], now: 1);
    }
}
