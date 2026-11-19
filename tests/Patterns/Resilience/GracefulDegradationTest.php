<?php

declare(strict_types=1);

namespace Tests\Patterns\Resilience;

use Patterns\Resilience\GracefulDegradation\FallbackChain;
use Patterns\Resilience\GracefulDegradation\FeatureDegrader;
use Patterns\Resilience\GracefulDegradation\LastKnownGood;
use Patterns\Resilience\GracefulDegradation\LoadLevel;
use Patterns\Support\FakeClock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GracefulDegradationTest extends TestCase
{
    // ---- Fallback chain -----------------------------------------------------------------

    public function test_when_the_primary_source_works_nothing_is_degraded(): void
    {
        $outcome = FallbackChain::startingWith('personalised', fn () => ['keyboard', 'mouse'])
            ->orElse('bestsellers', fn () => ['usb cable'])
            ->resolve();

        $this->assertSame(['keyboard', 'mouse'], $outcome->value);
        $this->assertFalse($outcome->degraded);
        $this->assertSame('personalised', $outcome->source);
    }

    public function test_when_the_primary_fails_the_user_gets_the_next_best_answer(): void
    {
        $outcome = FallbackChain::startingWith('personalised', fn () => throw new RuntimeException('recommender timed out'))
            ->orElse('bestsellers', fn () => ['usb cable'])
            ->resolve();

        $this->assertSame(['usb cable'], $outcome->value, 'Less relevant, but far better than an error page.');
        $this->assertTrue($outcome->degraded);
        $this->assertSame('bestsellers', $outcome->source);
        $this->assertSame(['personalised' => 'recommender timed out'], $outcome->failures, 'The failure is reported, not swallowed.');
    }

    public function test_the_chain_walks_down_from_best_to_worst(): void
    {
        $outcome = FallbackChain::startingWith('personalised', fn () => throw new RuntimeException('recommender down'))
            ->orElse('bestsellers', fn () => throw new RuntimeException('cache down'))
            ->orElse('nothing', fn () => [])
            ->resolve();

        $this->assertSame([], $outcome->value, 'An empty carousel: the page simply has one section less.');
        $this->assertSame('nothing', $outcome->source);
        $this->assertSame(['personalised', 'bestsellers'], array_keys($outcome->failures));
    }

    public function test_a_later_source_is_not_called_while_an_earlier_one_works(): void
    {
        $fallbackCalled = false;

        FallbackChain::startingWith('primary', fn () => 'fresh')
            ->orElse('fallback', function () use (&$fallbackCalled) {
                $fallbackCalled = true;
            })
            ->resolve();

        $this->assertFalse($fallbackCalled);
    }

    public function test_essential_data_has_no_default_and_fails_loudly(): void
    {
        // There is no sensible made-up value for a price.
        $price = FallbackChain::startingWith('pricing-service', fn () => throw new RuntimeException('pricing unavailable'))
            ->orElse('price-cache', fn () => throw new RuntimeException('no cached price'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no cached price');

        $price->resolve();
    }

    // ---- Serve stale on error -----------------------------------------------------------

    public function test_the_last_successful_answer_is_served_when_the_live_call_fails(): void
    {
        $clock = new FakeClock;
        $rates = new LastKnownGood($clock);

        $fresh = $rates->fetch('eur-usd', fn () => 1.08);
        $this->assertFalse($fresh->degraded);

        $clock->advanceSeconds(90);
        $stale = $rates->fetch('eur-usd', fn () => throw new RuntimeException('rates API is down'));

        $this->assertSame(1.08, $stale->value);
        $this->assertTrue($stale->degraded);
        $this->assertSame('last-known-good', $stale->source);
        $this->assertSame(90_000, $rates->ageMs('eur-usd'), 'The caller can show "as of 90 seconds ago".');
    }

    public function test_the_live_source_is_always_tried_first(): void
    {
        $rates = new LastKnownGood(new FakeClock);

        $rates->fetch('eur-usd', fn () => 1.08);
        $updated = $rates->fetch('eur-usd', fn () => 1.09);

        $this->assertSame(1.09, $updated->value, 'This is a safety net, not a cache: fresh data wins whenever it is available.');
    }

    public function test_without_any_earlier_success_the_failure_surfaces(): void
    {
        $this->expectExceptionMessage('rates API is down');

        (new LastKnownGood(new FakeClock))->fetch('eur-usd', fn () => throw new RuntimeException('rates API is down'));
    }

    public function test_stale_data_is_only_acceptable_up_to_a_point(): void
    {
        $clock = new FakeClock;
        $rates = new LastKnownGood($clock, maxAgeMs: 60_000);
        $rates->fetch('eur-usd', fn () => 1.08);

        $clock->advanceSeconds(3_600);

        $this->expectExceptionMessage('too old to serve');
        $rates->fetch('eur-usd', fn () => throw new RuntimeException('rates API is down'));
    }

    // ---- Planned degradation under load -------------------------------------------------

    private function shopFeatures(): FeatureDegrader
    {
        return new FeatureDegrader([
            'recommendations' => LoadLevel::Elevated,
            'reviews' => LoadLevel::High,
            'search-suggestions' => LoadLevel::High,
            'product-search' => LoadLevel::Critical,
            // 'checkout' is not listed: it is never switched off.
        ]);
    }

    public function test_optional_features_are_switched_off_in_a_planned_order_as_load_rises(): void
    {
        $features = $this->shopFeatures();

        $this->assertSame([], $features->disabledFeatures());

        $features->setLevel(LoadLevel::Elevated);
        $this->assertSame(['recommendations'], $features->disabledFeatures());

        $features->setLevel(LoadLevel::High);
        $this->assertSame(['recommendations', 'reviews', 'search-suggestions'], $features->disabledFeatures());

        $features->setLevel(LoadLevel::Critical);
        $this->assertFalse($features->isEnabled('product-search'));
    }

    public function test_essential_features_survive_every_load_level(): void
    {
        $features = $this->shopFeatures();
        $features->setLevel(LoadLevel::Critical);

        $this->assertTrue($features->isEnabled('checkout'), 'People can still pay, which is the point of keeping the lights on.');
    }

    public function test_features_come_back_when_the_load_drops(): void
    {
        $features = $this->shopFeatures();
        $features->setLevel(LoadLevel::High);
        $features->setLevel(LoadLevel::Normal);

        $this->assertTrue($features->isEnabled('reviews'));
    }

    public function test_a_page_is_assembled_from_whatever_is_available(): void
    {
        $features = $this->shopFeatures();
        $features->setLevel(LoadLevel::Elevated); // recommendations are off

        $page = ['title' => 'Mechanical Keyboard', 'price' => '$129.00'];

        if ($features->isEnabled('recommendations')) {
            $page['recommendations'] = ['wrist rest'];
        }

        if ($features->isEnabled('reviews')) {
            $page['reviews'] = FallbackChain::startingWith('reviews-service', fn () => throw new RuntimeException('reviews down'))
                ->orElse('none', fn () => [])
                ->resolve()
                ->value;
        }

        $this->assertSame(
            ['title' => 'Mechanical Keyboard', 'price' => '$129.00', 'reviews' => []],
            $page,
            'One feature shed on purpose, one dependency down, and the customer can still buy.'
        );
    }
}
