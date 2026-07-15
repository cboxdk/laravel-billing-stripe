<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Cbox\Billing\Stripe\StripeApiWebhookVerifier;

/**
 * Exercises the real Stripe SDK verifier ({@see Webhook::constructEvent()}) against a
 * genuinely-signed payload — no mock over the crypto. The signature is computed exactly
 * as Stripe computes it (HMAC-SHA256 over `{$timestamp}.{$body}`), so the SDK's own
 * verification runs, and we assert the normalisation onto the shared WebhookEvent.
 */
const SECRET = 'whsec_test_secret';

function signed(string $body, ?int $timestamp = null): WebhookPayload
{
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", SECRET);

    return new WebhookPayload($body, ['Stripe-Signature' => "t={$timestamp},v1={$signature}"]);
}

function eventBody(string $type = 'payment_intent.succeeded', string $status = 'succeeded'): string
{
    return (string) json_encode([
        'id' => 'evt_test_123',
        'object' => 'event',
        'type' => $type,
        'data' => ['object' => [
            'id' => 'pi_test_123',
            'object' => 'payment_intent',
            'amount' => 12500,
            'currency' => 'eur',
            'status' => $status,
            'metadata' => ['reference' => 'DK-000001'],
        ]],
    ]);
}

it('normalises a genuinely-signed succeeded event onto the shared WebhookEvent', function () {
    $event = (new StripeApiWebhookVerifier(SECRET))->verify(signed(eventBody()));

    expect($event->id)->toBe('evt_test_123')
        ->and($event->type)->toBe(WebhookEventType::PaymentSettled)
        ->and($event->reference)->toBe('DK-000001')
        ->and($event->amount)->toEqual(Money::ofMinor(12500, 'EUR'))
        ->and($event->isSettlement())->toBeTrue();
});

it('maps an explicit failure event to a non-settling failure notice', function () {
    $event = (new StripeApiWebhookVerifier(SECRET))->verify(signed(eventBody('payment_intent.payment_failed', 'requires_payment_method')));

    expect($event->type)->toBe(WebhookEventType::PaymentFailed)
        ->and($event->isSettlement())->toBeFalse();
});

it('maps any other authentic event to a pending notice (no effect)', function () {
    $event = (new StripeApiWebhookVerifier(SECRET))->verify(signed(eventBody('payment_intent.processing', 'processing')));

    expect($event->type)->toBe(WebhookEventType::PaymentPending)
        ->and($event->isSettlement())->toBeFalse();
});

it('rejects a payload with no signature header (deny-by-default)', function () {
    (new StripeApiWebhookVerifier(SECRET))->verify(new WebhookPayload(eventBody()));
})->throws(WebhookVerificationFailed::class);

it('rejects a tampered body whose signature no longer matches', function () {
    $payload = signed(eventBody());
    $tampered = new WebhookPayload($payload->body.' ', $payload->headers);

    (new StripeApiWebhookVerifier(SECRET))->verify($tampered);
})->throws(WebhookVerificationFailed::class);
