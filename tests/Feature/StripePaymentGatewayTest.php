<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
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

function gateway(FakeStripeIntentCreator $creator, ?FakeSettledPaymentStore $settled = null): StripePaymentGateway
{
    return new StripePaymentGateway($creator, $settled ?? new FakeSettledPaymentStore);
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
