<?php

declare(strict_types=1);

namespace ForgeStack\IdempotentPayments;

use Illuminate\Support\ServiceProvider;

/**
 * This provider does NOT bind PaymentGatewayInterface to anything — unlike
 * a single-tenant package that picks one driver from config, this package
 * assumes your app decides which gateway to construct (per request, per
 * tenant, per product — whatever "which gateway" means for you) and passes
 * it directly into CreatePaymentIntent/ProcessPaymentWebhook. There is no
 * sensible single default beyond FakeGateway, which you construct yourself.
 */
class IdempotentPaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Migrations are published, not auto-loaded: run
        // `vendor:publish --tag=idempotent-payments-migrations` then `migrate`.
        // Auto-loading them here as well would register two files with the
        // same migration name once published, resolved ambiguously depending
        // on path registration order.
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'idempotent-payments-migrations');
        }

        // The package only emits events — listeners live in the host app.
    }
}
