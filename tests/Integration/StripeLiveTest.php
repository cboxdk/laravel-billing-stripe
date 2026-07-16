<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\Webhook\Storage\InMemorySettledPaymentStore;
use Cbox\Billing\Stripe\StripeApiIntentCreator;
use Cbox\Billing\Stripe\StripePaymentGateway;
use Cbox\Billing\Stripe\Testing\FakeStripeIntentCreator;
use Stripe\StripeClient;

/**
 * Live sandbox integration suite for the REAL Stripe SDK path
 * ({@see StripeApiIntentCreator} + {@see StripePaymentGateway}), which the rest of the
 * test-bed only proves against the {@see FakeStripeIntentCreator}.
 *
 * It hits Stripe TEST MODE and is gated on a dedicated `STRIPE_TEST_SECRET` (never
 * `STRIPE_SECRET`, so it can never collide with a production key). Without that env var
 * the whole file skips cleanly — the default `pest` / `composer qa` run stays green and
 * reports the skip. Run it on demand with `vendor/bin/pest --group=integration`.
 *
 * Only Stripe's canned test payment methods (`pm_card_visa`) and tiny test-currency
 * amounts are used — never real card data, never a real money movement. Every object it
 * creates is a throwaway that it tears down (detach the method, cancel intents, delete the
 * customer) in a `finally` so a failure mid-flight still cleans up after itself.
 */
pest()->group('integration');

beforeEach(function (): void {
    if (! env('STRIPE_TEST_SECRET')) {
        test()->markTestSkipped('set STRIPE_TEST_SECRET to run');
    }
});

/**
 * The test-mode secret, as a string (the beforeEach guard has already skipped when it is
 * absent, so this only runs with a usable key).
 */
function liveStripeSecret(): string
{
    $secret = env('STRIPE_TEST_SECRET');

    return is_string($secret) ? $secret : '';
}

/**
 * The real gateway, wired exactly as the service provider wires it in production: a real
 * {@see StripeClient} behind the real {@see StripeApiIntentCreator}, with an in-memory
 * settle-once store (durability is not what this suite exercises).
 */
function liveGateway(StripeClient $client): StripePaymentGateway
{
    return new StripePaymentGateway(
        new StripeApiIntentCreator($client),
        new InMemorySettledPaymentStore,
        'pk_test_live_integration_suite',
    );
}

/** The `pi_…` id embedded in a PaymentIntent client secret (`pi_…_secret_…`). */
function paymentIntentIdFromSecret(?string $clientSecret): string
{
    return explode('_secret_', (string) $clientSecret, 2)[0];
}

it('runs the full stored-customer/method lifecycle against Stripe test mode', function () {
    $client = new StripeClient(liveStripeSecret());
    $gateway = liveGateway($client);

    // A fresh account per run keeps every external idempotency key unique, so a re-run is
    // never a Stripe idempotency replay of a prior run.
    $account = 'IT-'.bin2hex(random_bytes(6));

    // 1. createCustomer → a real cus_… id whose metadata[account] is stamped for reconciliation.
    $customer = $gateway->createCustomer($account, 'ada@example.test', 'Ada Lovelace');

    expect($customer)->toStartWith('cus_');

    try {
        $fetched = $client->customers->retrieve($customer);

        expect($fetched->metadata['account'] ?? null)->toBe($account)
            ->and($fetched->email)->toBe('ada@example.test')
            ->and($fetched->name)->toBe('Ada Lovelace');

        // 2. createSetupIntent → an off-session setup intent that hands back a client secret.
        $setup = $gateway->createSetupIntent(new SetupIntentRequest($customer, 'it-seti-'.$account));

        expect($setup->gateway)->toBe('stripe')
            ->and($setup->publishableKey)->toBe('pk_test_live_integration_suite')
            ->and($setup->clientSecret)->toBeString()->not->toBe('')
            ->and($setup->reference)->toStartWith('seti_');

        // 3. attachPaymentMethod(pm_card_visa) → setDefaultPaymentMethod → paymentMethods:
        //    the method is listed and flagged default.
        $attached = $gateway->attachPaymentMethod($customer, 'pm_card_visa');

        expect($attached->id)->toStartWith('pm_')
            ->and($attached->brand)->toBe('visa')
            ->and($attached->last4)->toBe('4242')
            ->and($attached->isDefault)->toBeFalse(); // a fresh attach is not yet the default

        $gateway->setDefaultPaymentMethod($customer, $attached->id);

        $methods = $gateway->paymentMethods($customer);

        expect($methods)->toHaveCount(1)
            ->and($methods[0]->id)->toBe($attached->id)
            ->and($methods[0]->brand)->toBe('visa')
            ->and($methods[0]->last4)->toBe('4242')
            ->and($methods[0]->isDefault)->toBeTrue();

        // 4. createPaymentIntent on-session against the saved method → a real client secret.
        $intent = $gateway->createPaymentIntent(new PaymentIntentRequest(
            $customer,
            'IT-INV-'.$account,
            Money::ofMinor(50, 'EUR'),
            'it-pi-'.$account,
            $attached->id,
        ));

        expect($intent->gateway)->toBe('stripe')
            ->and($intent->clientSecret)->toBeString()->not->toBe('')
            ->and($intent->reference)->toBe('IT-INV-'.$account)
            ->and($intent->amount)->toEqual(Money::ofMinor(50, 'EUR'))
            ->and($intent->status)->not->toBe(PaymentIntentStatus::Canceled);

        // Tidy up the un-confirmed on-session intent so nothing is left half-open.
        $client->paymentIntents->cancel(paymentIntentIdFromSecret($intent->clientSecret));

        // 5. charge() → the real create() SDK path returns a live pi_… without throwing. A bare
        //    intent (no confirmation) is not settled; it maps to RequiresAction. Then cancel it.
        $charge = $gateway->charge(new PaymentIntent('pi-charge', Money::ofMinor(50, 'EUR'), 'IT-CHG-'.$account));

        expect($charge->status)->toBe(PaymentStatus::RequiresAction)
            ->and($charge->gatewayReference)->toStartWith('pi_');

        $client->paymentIntents->cancel($charge->gatewayReference);

        // 6. refund() round-trip: confirm a tiny charge on the saved method (test card settles
        //    immediately), then refund it THROUGH the gateway — exercising the real refund() SDK
        //    path — and assert it settles.
        $paid = $client->paymentIntents->create([
            'amount' => 50,
            'currency' => 'eur',
            'customer' => $customer,
            'payment_method' => $attached->id,
            'confirm' => true,
            'off_session' => true,
        ]);

        expect((string) $paid->status)->toBe('succeeded');

        $refund = $gateway->refund(new RefundIntent(
            'IT-CN-'.$account,
            Money::ofMinor(50, 'EUR'),
            'IT-CN-'.$account,
            'it-refund-'.$account,
            (string) $paid->id,
        ));

        expect($refund->isSettled())->toBeTrue()
            ->and($refund->gatewayReference)->toStartWith('re_');

        // 7. detachPaymentMethod → the method is gone from paymentMethods; a repeat detach of the
        //    now-detached method is idempotent (no throw, still gone).
        $gateway->detachPaymentMethod($customer, $attached->id);

        expect($gateway->paymentMethods($customer))->toBe([]);

        $gateway->detachPaymentMethod($customer, $attached->id);

        expect($gateway->paymentMethods($customer))->toBe([]);
    } finally {
        // Tear down the throwaway customer (this also detaches any still-attached methods).
        $client->customers->delete($customer);
    }
});
