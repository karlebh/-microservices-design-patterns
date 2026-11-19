<?php

declare(strict_types=1);

namespace Patterns\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Patterns\DataConsistency\DistributedLocks\Newsletter\SendNewsletterCommand;
use Patterns\DataConsistency\Idempotency\Webhooks\ProcessWebhookCommand;
use Patterns\DataConsistency\TransactionalOutbox\Console\RelayOutboxCommand;
use Patterns\DataConsistency\TransactionalOutbox\LogPublisher;
use Patterns\DataConsistency\TransactionalOutbox\MessagePublisher;

/**
 * Glue between Laravel and the pattern folders in src/.
 *
 * Every pattern lives in src/<Section>/<Pattern>/ and owns everything it
 * needs. The few patterns that touch real Laravel infrastructure plug in by
 * convention, so nothing has to be registered by hand when a pattern is added:
 *
 *   src/<Section>/<Pattern>/Migrations/   -> picked up by `php artisan migrate`
 *   src/<Section>/<Pattern>/routes.php    -> served under /api
 *
 * Most patterns are plain PHP and need none of this.
 */
final class PatternsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Default transport for the outbox relay. Swap for a Kafka/RabbitMQ publisher in a real system.
        $this->app->bind(MessagePublisher::class, LogPublisher::class);
    }

    public function boot(): void
    {
        $src = dirname(__DIR__);

        $this->loadMigrationsFrom(glob($src.'/*/*/Migrations', GLOB_ONLYDIR) ?: []);

        foreach (glob($src.'/*/*/routes.php') ?: [] as $routes) {
            Route::middleware('api')->prefix('api')->group($routes);
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                RelayOutboxCommand::class,
                ProcessWebhookCommand::class,
                SendNewsletterCommand::class,
            ]);
        }
    }
}
