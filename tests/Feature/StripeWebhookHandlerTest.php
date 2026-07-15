<?php

declare(strict_types=1);

use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Stripe\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Stripe\StripeWebhookHandler;
use Cbox\Billing\Stripe\Testing\FakeProcessedEventStore;
use Cbox\Billing\Stripe\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Stripe\Testing\FakeWebhookVerifier;
use Cbox\Billing\Stripe\ValueObjects\WebhookEvent;

function handler(
    WebhookEvent $event,
    ?FakeProcessedEventStore $processed = null,
    ?FakeSettledPaymentStore $settled = null,
    bool $reject = false,
): StripeWebhookHandler {
    return new StripeWebhookHandler(
        new FakeWebhookVerifier($event, $reject),
        $processed ?? new FakeProcessedEventStore,
        $settled ?? new FakeSettledPaymentStore,
    );
}

function succeededEvent(string $eventId = 'evt_1', string $reference = 'DK-000001'): WebhookEvent
{
    return new WebhookEvent($eventId, 'payment_intent.succeeded', $reference, 'pi_live', 'succeeded');
}

it('rejects an unverified payload (deny-by-default)', function () {
    handler(succeededEvent(), reject: true)->handle('{}', 'bad-sig');
})->throws(WebhookVerificationFailed::class);

it('maps a verified succeeded event to a settled result', function () {
    $result = handler(succeededEvent())->handle('{}', 'sig');

    expect($result)->not->toBeNull()
        ->and($result->isSettled())->toBeTrue()
        ->and($result->gatewayReference)->toBe('pi_live');
});

it('dedups a replayed delivery of the same event id to a no-op', function () {
    $processed = new FakeProcessedEventStore;

    $first = handler(succeededEvent('evt_dup'), $processed)->handle('{}', 'sig');
    $second = handler(succeededEvent('evt_dup'), $processed)->handle('{}', 'sig');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('settles a reference only once across different events (per-reference idempotency)', function () {
    $settled = new FakeSettledPaymentStore;

    $first = handler(succeededEvent('evt_a', 'DK-42'), settled: $settled)->handle('{}', 'sig');
    $second = handler(succeededEvent('evt_b', 'DK-42'), settled: $settled)->handle('{}', 'sig');

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('is a no-op when the inline path already settled the reference (backstop)', function () {
    $settled = new FakeSettledPaymentStore;
    $settled->markSettled('DK-000001');

    $result = handler(succeededEvent(), settled: $settled)->handle('{}', 'sig');

    expect($result)->toBeNull();
});

it('ignores events that are not payment-intent lifecycle events', function () {
    $event = new WebhookEvent('evt_x', 'charge.refunded', 'DK-000001', 'ch_1', 'refunded');

    expect(handler($event)->handle('{}', 'sig'))->toBeNull();
});

it('returns a non-settle result without touching the settle guard', function () {
    $settled = new FakeSettledPaymentStore;
    $event = new WebhookEvent('evt_p', 'payment_intent.processing', 'DK-000001', 'pi_live', 'processing');

    $result = handler($event, settled: $settled)->handle('{}', 'sig');

    expect($result->status)->toBe(PaymentStatus::Pending)
        ->and($settled->isSettled('DK-000001'))->toBeFalse();
});
