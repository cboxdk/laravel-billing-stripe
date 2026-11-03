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
use Cbox\Billing\Stripe\StripeApiDisputes;
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

        // 5. charge() with no vaulted instrument is not off-session: since v0.5.0 the gateway
        //    refuses it as FAILED (which opens dunning) and never creates an intent at Stripe.
        $charge = $gateway->charge(new PaymentIntent('pi-charge', Money::ofMinor(50, 'EUR'), 'IT-CHG-'.$account));

        expect($charge->status)->toBe(PaymentStatus::Failed)
            ->and($charge->gatewayReference)->toBeNull();

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

it('lists a saved bank debit and charges it off-session, where it waits in processing', function (string $type, string $currency, array $details, string $last4) {
    $client = new StripeClient(liveStripeSecret());
    $gateway = liveGateway($client);
    $account = 'IT-'.bin2hex(random_bytes(6));
    $customer = $gateway->createCustomer($account, 'ada@example.test', 'Ada Lovelace');

    try {
        // Saved the way a customer saves one in the element: a confirmed off-session SetupIntent
        // with the mandate the customer accepted. ACH is verified with Stripe's test microdeposits.
        $setup = $client->setupIntents->create([
            'customer' => $customer,
            'payment_method_types' => [$type],
            'usage' => 'off_session',
            'confirm' => true,
            'payment_method_data' => ['type' => $type, $type => $details, 'billing_details' => [
                'name' => 'Ada Lovelace', 'email' => 'ada@example.test',
                'address' => ['line1' => '1 Main St', 'city' => 'Austin', 'state' => 'TX', 'postal_code' => '78701', 'country' => 'US'],
            ]],
            'payment_method_options' => $type === 'us_bank_account' ? ['us_bank_account' => ['verification_method' => 'microdeposits']] : [],
            'mandate_data' => ['customer_acceptance' => ['type' => 'online', 'online' => ['ip_address' => '127.0.0.1', 'user_agent' => 'integration']]],
        ]);

        if ($type === 'us_bank_account') {
            $setup = $client->setupIntents->verifyMicrodeposits((string) $setup->id, ['amounts' => [32, 45]]);
        }

        expect((string) $setup->status)->toBe('succeeded');

        $method = (string) $setup->payment_method;
        $gateway->setDefaultPaymentMethod($customer, $method);
        $methods = $gateway->paymentMethods($customer);

        expect($methods)->toHaveCount(1)
            ->and($methods[0]->id)->toBe($method)
            ->and($methods[0]->brand)->toBe($type)
            ->and($methods[0]->last4)->toBe($last4)
            ->and($methods[0]->expMonth)->toBeNull()
            ->and($methods[0]->isDefault)->toBeTrue();

        // The intent allows the method's own type, so Stripe pulls the mandate. A debit clears
        // over days: it waits in processing.
        $charge = $gateway->charge(new PaymentIntent('it-'.$type, Money::ofMinor(100, $currency), 'IT-DD-'.$account, $customer, $method));

        expect($charge->status)->toBe(PaymentStatus::Pending)
            ->and($charge->gatewayReference)->toStartWith('pi_');
    } finally {
        $client->customers->delete($customer);
    }
})->with([
    'SEPA Direct Debit' => ['sepa_debit', 'EUR', ['iban' => 'AT611904300234573201'], '3201'],
    'ACH Direct Debit' => ['us_bank_account', 'USD', ['routing_number' => '110000000', 'account_number' => '000123456789', 'account_holder_type' => 'individual'], '6789'],
]);

it('reads a real dispute, uploads a file and submits evidence that Stripe accepts', function () {
    $client = new StripeClient(liveStripeSecret());
    $disputes = new StripeApiDisputes($client);
    $customer = $client->customers->create(['metadata' => ['account' => 'IT-dispute-'.bin2hex(random_bytes(4))]])->id;

    try {
        // Stripe's test card that is always disputed once it is charged.
        $intent = $client->paymentIntents->create([
            'amount' => 1500,
            'currency' => 'dkk',
            'customer' => $customer,
            'payment_method' => 'pm_card_createDispute',
            'payment_method_types' => ['card'],
            'confirm' => true,
        ]);

        $disputeId = '';
        $deadline = time() + 60;

        while ($disputeId === '' && time() < $deadline) {
            $found = $client->disputes->all(['payment_intent' => $intent->id, 'limit' => 1])->data;
            $disputeId = isset($found[0]) ? (string) $found[0]->id : '';

            if ($disputeId === '') {
                sleep(2);
            }
        }

        expect($disputeId)->toStartWith('du_');

        $dispute = $disputes->retrieve($disputeId);
        expect($dispute['status'])->toBe('needs_response')
            ->and($dispute['paymentIntent'])->toBe($intent->id)
            ->and($dispute['dueBy'])->toBeInt();

        // A one-pixel PNG: Stripe checks the file type, not what the picture shows.
        $file = $disputes->uploadEvidenceFile('receipt.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', true));
        expect($file)->toStartWith('file_');

        $submitted = $disputes->submitEvidence($disputeId, [
            'receipt' => $file,
            'customer_name' => 'Integration Test',
            'uncategorized_text' => 'Evidence sent by the adapter integration suite.',
        ]);

        expect($submitted['status'])->toBe('under_review')
            ->and($submitted['submissionCount'])->toBe(1)
            ->and($submitted['hasEvidence'])->toBeTrue();
    } finally {
        $client->customers->delete($customer);
    }
});
