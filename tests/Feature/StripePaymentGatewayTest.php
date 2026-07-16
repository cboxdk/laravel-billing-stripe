<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Stripe\StripePaymentGateway;
use Cbox\Billing\Stripe\Testing\FakeStripeIntentCreator;

function paymentIntent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

function refundIntent(): RefundIntent
{
    return new RefundIntent('cn_1', Money::ofMinor(12500, 'EUR'), 'CN-000001', 'cbx-refund-CN-000001', 'pi_live');
}

function gateway(FakeStripeIntentCreator $creator, ?FakeSettledPaymentStore $settled = null, string $publishableKey = 'pk_test_123'): StripePaymentGateway
{
    return new StripePaymentGateway($creator, $settled ?? new FakeSettledPaymentStore, $publishableKey);
}

it('is named stripe', function () {
    expect(gateway(new FakeStripeIntentCreator)->name())->toBe('stripe');
});

it('maps a succeeded intent to a settled result', function () {
    $result = gateway(new FakeStripeIntentCreator('succeeded', 'pi_live'))->charge(paymentIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('pi_live');
});

it('maps processing to pending and requires_action to requires-action', function () {
    $processing = gateway(new FakeStripeIntentCreator('processing'))->charge(paymentIntent());
    $action = gateway(new FakeStripeIntentCreator('requires_action'))->charge(paymentIntent());

    expect($processing->status)->toBe(PaymentStatus::Pending)
        ->and($action->status)->toBe(PaymentStatus::RequiresAction);
});

it('turns an API failure into a failed result without throwing', function () {
    $result = gateway(new FakeStripeIntentCreator(fail: true))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('card_declined');
});

it('treats an unexpected status as failed', function () {
    $result = gateway(new FakeStripeIntentCreator('canceled'))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed);
});

it('passes a scoped external idempotency key derived from reference, amount and currency', function () {
    $creator = new FakeStripeIntentCreator;

    gateway($creator)->charge(paymentIntent());

    expect($creator->idempotencyKeys)->toBe(['cbx-DK-000001-12500-EUR']);
});

it('records the reference as settled on a succeeded charge (webhook backstop)', function () {
    $settled = new FakeSettledPaymentStore;

    gateway(new FakeStripeIntentCreator('succeeded'), $settled)->charge(paymentIntent());

    expect($settled->isSettled('DK-000001'))->toBeTrue();
});

it('does not record settlement when the charge is not settled', function () {
    $settled = new FakeSettledPaymentStore;

    gateway(new FakeStripeIntentCreator('processing'), $settled)->charge(paymentIntent());

    expect($settled->isSettled('DK-000001'))->toBeFalse();
});

it('maps a succeeded refund to a settled result and passes the scoped idempotency key', function () {
    $creator = new FakeStripeIntentCreator;

    $result = gateway($creator)->refund(refundIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('re_fake')
        ->and($creator->refundIdempotencyKeys)->toBe(['cbx-refund-CN-000001']);
});

it('turns a refund API failure into a failed result without throwing', function () {
    $result = gateway(new FakeStripeIntentCreator(fail: true))->refund(refundIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('refund_failed');
});

it('creates an on-session payment intent shaped for the frontend element', function () {
    $creator = new FakeStripeIntentCreator;
    $request = new PaymentIntentRequest('cus_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-1');

    $result = gateway($creator)->createPaymentIntent($request);

    expect($result->gateway)->toBe('stripe')
        ->and($result->publishableKey)->toBe('pk_test_123')
        ->and($result->clientSecret)->toBe('pi_intent_secret_idem-pi-1')
        ->and($result->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($result->reference)->toBe('DK-000001')
        ->and($result->amount)->toEqual(Money::ofMinor(12500, 'EUR'))
        ->and($creator->intentIdempotencyKeys)->toBe(['idem-pi-1'])
        ->and($creator->intentPaymentMethodIds)->toBe([null]);
});

it('maps a 3-D Secure payment intent to RequiresAction and reports it needs customer action', function () {
    $creator = new FakeStripeIntentCreator(intentStatus: 'requires_action');
    $request = new PaymentIntentRequest('cus_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-2', 'pm_saved');

    $result = gateway($creator)->createPaymentIntent($request);

    expect($result->status)->toBe(PaymentIntentStatus::RequiresAction)
        ->and($result->requiresCustomerAction())->toBeTrue()
        ->and($creator->intentPaymentMethodIds)->toBe(['pm_saved']);
});

it('omits the publishable key when none is configured', function () {
    $request = new PaymentIntentRequest('cus_1', 'DK-000001', Money::ofMinor(12500, 'EUR'), 'idem-pi-3');

    $result = gateway(new FakeStripeIntentCreator, publishableKey: '')->createPaymentIntent($request);

    expect($result->publishableKey)->toBeNull();
});

it('creates an off-session setup intent that saves a method for renewals', function () {
    $creator = new FakeStripeIntentCreator;
    $request = new SetupIntentRequest('cus_1', 'idem-seti-1');

    $result = gateway($creator)->createSetupIntent($request);

    expect($result->gateway)->toBe('stripe')
        ->and($result->publishableKey)->toBe('pk_test_123')
        ->and($result->clientSecret)->toBe('seti_secret_idem-seti-1')
        ->and($result->status)->toBe(PaymentIntentStatus::Succeeded)
        ->and($result->reference)->toBe('seti_fake')
        ->and($creator->setupIdempotencyKeys)->toBe(['idem-seti-1']);
});

it('creates a customer, returns its ref, and stamps the account for reconciliation', function () {
    $creator = new FakeStripeIntentCreator;

    $ref = gateway($creator)->createCustomer('DK-000001', 'ada@example.test', 'Ada Lovelace');

    expect($ref)->toBe('cus_test_DK-000001')
        ->and($creator->customerCalls)->toBe([
            ['account' => 'DK-000001', 'email' => 'ada@example.test', 'name' => 'Ada Lovelace'],
        ])
        ->and($creator->customerFor('DK-000001'))->toBe('cus_test_DK-000001');
});

it('re-resolves the same customer ref on a repeat create (mint once)', function () {
    $creator = new FakeStripeIntentCreator;
    $gw = gateway($creator);

    $first = $gw->createCustomer('DK-000001');
    $second = $gw->createCustomer('DK-000001');

    expect($second)->toBe($first)
        ->and($creator->customerCalls)->toHaveCount(2);
});

it('detaches a payment method by delegating to the seam and is idempotent on a repeat', function () {
    $creator = new FakeStripeIntentCreator;
    $gw = gateway($creator);

    $gw->attachPaymentMethod('cus_1', 'pm_a');
    $gw->attachPaymentMethod('cus_1', 'pm_b');

    $gw->detachPaymentMethod('cus_1', 'pm_a');

    expect($gw->paymentMethods('cus_1'))->toHaveCount(1)
        ->and(collect($gw->paymentMethods('cus_1'))->pluck('id')->all())->toBe(['pm_b']);

    // A repeat detach of the already-gone method is a clean no-op, not an error.
    $gw->detachPaymentMethod('cus_1', 'pm_a');

    expect($gw->paymentMethods('cus_1'))->toHaveCount(1)
        ->and($creator->detachments)->toBe([
            ['account' => 'cus_1', 'methodId' => 'pm_a'],
            ['account' => 'cus_1', 'methodId' => 'pm_a'],
        ]);
});

it('attaches a payment method, lists it, and makes it the default', function () {
    $gw = gateway(new FakeStripeIntentCreator);

    expect($gw->paymentMethods('cus_1'))->toBe([]);

    $first = $gw->attachPaymentMethod('cus_1', 'pm_a');
    $second = $gw->attachPaymentMethod('cus_1', 'pm_b');

    expect($first->id)->toBe('pm_a')
        ->and($first->brand)->toBe('visa')
        ->and($first->last4)->toBe('4242')
        ->and($first->isDefault)->toBeTrue()
        ->and($second->isDefault)->toBeFalse()
        ->and($gw->paymentMethods('cus_1'))->toHaveCount(2);

    $gw->setDefaultPaymentMethod('cus_1', 'pm_b');

    $methods = $gw->paymentMethods('cus_1');
    $byId = collect($methods)->keyBy->id;

    expect($byId['pm_a']->isDefault)->toBeFalse()
        ->and($byId['pm_b']->isDefault)->toBeTrue();
});
