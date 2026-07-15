<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Stripe\Contracts\ProcessedEventStore;
use Cbox\Billing\Stripe\Contracts\SettledPaymentStore;
use Cbox\Billing\Stripe\Contracts\WebhookVerifier;
use Cbox\Billing\Stripe\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Stripe\ValueObjects\WebhookEvent;

/**
 * Turns a raw Stripe webhook delivery into an idempotent {@see PaymentResult}. Three
 * layers make replays and retries safe:
 *
 *  1. Signature verification (deny-by-default) — an unverified payload never touches
 *     state; it throws {@see WebhookVerificationFailed}.
 *  2. Event-id dedup — a delivery whose Stripe event id (`evt_…`) was already
 *     processed is a no-op (returns null).
 *  3. Per-reference settle-once + backstop — a `succeeded` event settles a reference
 *     only if it is not already settled; if the inline `charge()` path (or an earlier
 *     event) already settled it, re-confirmation is a no-op.
 *
 * Returns the mapped result for the host to apply, or null when the delivery is a
 * duplicate / not actionable.
 */
readonly class StripeWebhookHandler
{
    public function __construct(
        private WebhookVerifier $verifier,
        private ProcessedEventStore $processedEvents,
        private SettledPaymentStore $settledPayments,
        private StripeStatusMapper $mapper = new StripeStatusMapper,
    ) {}

    /**
     * @throws WebhookVerificationFailed
     */
    public function handle(string $payload, string $signatureHeader): ?PaymentResult
    {
        $event = $this->verifier->verify($payload, $signatureHeader);

        // We only act on payment-intent lifecycle events; anything else is ignored
        // (and deliberately not recorded, so the store stays scoped to what we handle).
        if (! str_starts_with($event->type, 'payment_intent.')) {
            return null;
        }

        // Event-id dedup: a replayed delivery of the same event is a no-op.
        if (! $this->processedEvents->remember($event->eventId)) {
            return null;
        }

        return $this->applyEffect($event);
    }

    private function applyEffect(WebhookEvent $event): ?PaymentResult
    {
        $result = $this->mapper->map($event->status, $event->gatewayReference);

        if (! $result->isSettled() || $event->reference === '') {
            return $result;
        }

        // Per-reference settle-once + no-op backstop: only the first path to settle
        // this reference applies the effect.
        if ($this->settledPayments->isSettled($event->reference)) {
            return null;
        }

        $this->settledPayments->markSettled($event->reference);

        return $result;
    }
}
