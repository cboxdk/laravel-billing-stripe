<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Stripe\StripePaymentGateway;
use Cbox\Billing\Stripe\Testing\FakeStripeIntentCreator;

function paymentIntent(): PaymentIntent
{
    return new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001');
}

it('is named stripe', function () {
    expect((new StripePaymentGateway(new FakeStripeIntentCreator))->name())->toBe('stripe');
});

it('maps a succeeded intent to a settled result', function () {
    $result = (new StripePaymentGateway(new FakeStripeIntentCreator('succeeded', 'pi_live')))->charge(paymentIntent());

    expect($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('pi_live');
});

it('maps processing to pending and requires_action to requires-action', function () {
    $processing = (new StripePaymentGateway(new FakeStripeIntentCreator('processing')))->charge(paymentIntent());
    $action = (new StripePaymentGateway(new FakeStripeIntentCreator('requires_action')))->charge(paymentIntent());

    expect($processing->status)->toBe(PaymentStatus::Pending)
        ->and($action->status)->toBe(PaymentStatus::RequiresAction);
});

it('turns an API failure into a failed result without throwing', function () {
    $result = (new StripePaymentGateway(new FakeStripeIntentCreator(fail: true)))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed)
        ->and($result->failureReason)->toBe('card_declined');
});

it('treats an unexpected status as failed', function () {
    $result = (new StripePaymentGateway(new FakeStripeIntentCreator('canceled')))->charge(paymentIntent());

    expect($result->status)->toBe(PaymentStatus::Failed);
});
