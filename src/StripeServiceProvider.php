<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Stripe\Contracts\ProcessedEventStore;
use Cbox\Billing\Stripe\Contracts\SettledPaymentStore;
use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Contracts\WebhookVerifier;
use Cbox\Billing\Stripe\Database\DatabaseProcessedEventStore;
use Cbox\Billing\Stripe\Database\DatabaseSettledPaymentStore;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

/**
 * Binds the Stripe gateway as billing's PaymentGateway when a secret key is
 * configured, and the idempotent webhook handler when a signing secret is configured.
 * Each is independent — without a key the provider stays out of the way and billing
 * keeps its default. The dedup/settlement stores default to durable database
 * implementations so idempotency survives across processes and retries.
 */
class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-stripe.php', 'billing-stripe');

        $config = $this->app->make(Config::class);

        $this->registerStores();
        $this->registerGateway($config);
        $this->registerWebhook($config);
    }

    private function registerStores(): void
    {
        $this->app->singleton(ProcessedEventStore::class, static fn (Application $app): DatabaseProcessedEventStore => new DatabaseProcessedEventStore(
            $app->make(DatabaseManager::class)->connection(),
        ));

        $this->app->singleton(SettledPaymentStore::class, static fn (Application $app): DatabaseSettledPaymentStore => new DatabaseSettledPaymentStore(
            $app->make(DatabaseManager::class)->connection(),
        ));
    }

    private function registerGateway(Config $config): void
    {
        $secret = $config->get('billing-stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return;
        }

        $this->app->singleton(StripeIntentCreator::class, static fn (): StripeApiIntentCreator => new StripeApiIntentCreator(new StripeClient($secret)));

        $this->app->singleton(PaymentGateway::class, static fn (Application $app): StripePaymentGateway => new StripePaymentGateway(
            $app->make(StripeIntentCreator::class),
            $app->make(SettledPaymentStore::class),
        ));
    }

    private function registerWebhook(Config $config): void
    {
        $webhookSecret = $config->get('billing-stripe.webhook_secret');

        if (! is_string($webhookSecret) || $webhookSecret === '') {
            return;
        }

        $this->app->singleton(WebhookVerifier::class, static fn (): StripeApiWebhookVerifier => new StripeApiWebhookVerifier($webhookSecret));

        $this->app->singleton(StripeWebhookHandler::class, static fn (Application $app): StripeWebhookHandler => new StripeWebhookHandler(
            $app->make(WebhookVerifier::class),
            $app->make(ProcessedEventStore::class),
            $app->make(SettledPaymentStore::class),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-stripe.php' => $this->app->configPath('billing-stripe.php'),
            ], 'billing-stripe-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'billing-stripe-migrations');
        }
    }
}
