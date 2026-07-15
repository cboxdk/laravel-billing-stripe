<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

/**
 * Binds the Stripe gateway as billing's PaymentGateway when a secret key is
 * configured. Without a key it stays out of the way and billing keeps its default.
 */
class StripeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing-stripe.php', 'billing-stripe');

        $secret = $this->app->make(Config::class)->get('billing-stripe.secret');

        if (! is_string($secret) || $secret === '') {
            return;
        }

        $this->app->singleton(StripeIntentCreator::class, static fn (): StripeApiIntentCreator => new StripeApiIntentCreator(new StripeClient($secret)));

        $this->app->singleton(PaymentGateway::class, static fn (Application $app): StripePaymentGateway => new StripePaymentGateway(
            $app->make(StripeIntentCreator::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/billing-stripe.php' => $this->app->configPath('billing-stripe.php'),
            ], 'billing-stripe-config');
        }
    }
}
