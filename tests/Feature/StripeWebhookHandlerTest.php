<?php

declare(strict_types=1);

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\Testing\FakeInvoicePaymentApplier;
use Cbox\Billing\Payment\Testing\FakeProcessedEventStore;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\Testing\FakeWebhookVerifier;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;
use Cbox\Billing\Stripe\StripeWebhookHandler;

/**
 * The adapter's handler is a thin composition over the shared seam: the shared
 * FakeWebhookVerifier stands in for the Stripe SDK verifier, and the real
 * DefaultWebhookIngest is dogfooded over the shared in-memory fakes so the assertions
 * read the very instances the flow wrote to.
 */
function ingest(
    FakeProcessedEventStore $processed,
    FakeSettledPaymentStore $settled,
    FakeInvoicePaymentApplier $applier,
): DefaultWebhookIngest {
    return new DefaultWebhookIngest($processed, $settled, $applier);
}

function settledEvent(string $eventId = 'evt_1', string $reference = 'DK-000001'): WebhookEvent
{
    return new WebhookEvent($eventId, WebhookEventType::PaymentSettled, $reference, Money::ofMinor(12500, 'EUR'));
}

function payload(): WebhookPayload
{
    return new WebhookPayload('{}', ['Stripe-Signature' => 't=1,v1=sig']);
}

it('rejects an unverified payload (deny-by-default)', function () {
    $handler = new StripeWebhookHandler(
        FakeWebhookVerifier::rejecting(),
        ingest(new FakeProcessedEventStore, new FakeSettledPaymentStore, new FakeInvoicePaymentApplier),
    );

    $handler->handle(payload());
})->throws(WebhookVerificationFailed::class);

it('applies a verified settlement exactly once through the shared ingest', function () {
    $applier = new FakeInvoicePaymentApplier;
    $handler = new StripeWebhookHandler(
        FakeWebhookVerifier::accepting(settledEvent()),
        ingest(new FakeProcessedEventStore, new FakeSettledPaymentStore, $applier),
    );

    $outcome = $handler->handle(payload());

    expect($outcome->wasApplied())->toBeTrue()
        ->and($applier->timesPaid('DK-000001'))->toBe(1)
        ->and($applier->amountPaid('DK-000001'))->toEqual(Money::ofMinor(12500, 'EUR'));
});

it('collapses a replayed delivery of the same event to a no-op', function () {
    $processed = new FakeProcessedEventStore;
    $settled = new FakeSettledPaymentStore;
    $applier = new FakeInvoicePaymentApplier;

    $handler = new StripeWebhookHandler(FakeWebhookVerifier::accepting(settledEvent('evt_dup')), ingest($processed, $settled, $applier));

    $first = $handler->handle(payload());
    $second = $handler->handle(payload());

    expect($first->wasApplied())->toBeTrue()
        ->and($second->wasApplied())->toBeFalse()
        ->and($applier->timesPaid('DK-000001'))->toBe(1);
});

it('settles a reference only once across different events (per-reference idempotency)', function () {
    $processed = new FakeProcessedEventStore;
    $settled = new FakeSettledPaymentStore;
    $applier = new FakeInvoicePaymentApplier;

    (new StripeWebhookHandler(FakeWebhookVerifier::accepting(settledEvent('evt_a', 'DK-42')), ingest($processed, $settled, $applier)))->handle(payload());
    (new StripeWebhookHandler(FakeWebhookVerifier::accepting(settledEvent('evt_b', 'DK-42')), ingest($processed, $settled, $applier)))->handle(payload());

    expect($applier->timesPaid('DK-42'))->toBe(1)
        ->and($settled->settledCount())->toBe(1);
});

it('is a no-op when the inline charge path already settled the reference (backstop)', function () {
    $settled = new FakeSettledPaymentStore;
    $settled->settle('DK-000001');
    $applier = new FakeInvoicePaymentApplier;

    $outcome = (new StripeWebhookHandler(
        FakeWebhookVerifier::accepting(settledEvent()),
        ingest(new FakeProcessedEventStore, $settled, $applier),
    ))->handle(payload());

    expect($outcome->wasApplied())->toBeFalse()
        ->and($applier->isPaid('DK-000001'))->toBeFalse();
});

it('ignores a verified non-settlement event without applying an effect', function () {
    $applier = new FakeInvoicePaymentApplier;
    $event = new WebhookEvent('evt_p', WebhookEventType::PaymentPending, 'DK-000001', Money::ofMinor(12500, 'EUR'));

    $outcome = (new StripeWebhookHandler(
        FakeWebhookVerifier::accepting($event),
        ingest(new FakeProcessedEventStore, new FakeSettledPaymentStore, $applier),
    ))->handle(payload());

    expect($outcome->wasApplied())->toBeFalse()
        ->and($applier->isPaid('DK-000001'))->toBeFalse();
});

it('re-applies after a host crash mid-apply persisted nothing (exactly-once)', function () {
    $processed = new FakeProcessedEventStore;
    $settled = new FakeSettledPaymentStore;
    $applier = new FakeInvoicePaymentApplier;
    $applier->crashOnNextApply();

    $handler = new StripeWebhookHandler(FakeWebhookVerifier::accepting(settledEvent('evt_crash')), ingest($processed, $settled, $applier));

    expect(fn () => $handler->handle(payload()))->toThrow(RuntimeException::class);
    expect($settled->isSettled('DK-000001'))->toBeFalse();

    $retry = $handler->handle(payload());

    expect($retry->wasApplied())->toBeTrue()
        ->and($applier->timesPaid('DK-000001'))->toBe(1);
});
