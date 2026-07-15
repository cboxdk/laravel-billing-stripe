<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\WebhookIngest;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\IngestOutcome;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;

/**
 * The adapter's inbound-webhook entry point: prove the Stripe delivery authentic, then
 * hand the normalised event to the engine's exactly-once ingest. It composes two shared
 * seams and owns no idempotency logic of its own:
 *
 *  1. {@see WebhookVerifier} — the Stripe-backed verifier proves the signature
 *     (deny-by-default: an unverified payload throws {@see WebhookVerificationFailed}
 *     and never reaches the ingest) and normalises the event.
 *  2. {@see WebhookIngest} — the engine's exactly-once ingest applies the paid effect
 *     to the invoice at most once per reference, collapsing gateway re-deliveries and
 *     crash-retries; the returned {@see IngestOutcome} tells the host what happened.
 */
readonly class StripeWebhookHandler
{
    public function __construct(
        private WebhookVerifier $verifier,
        private WebhookIngest $ingest,
    ) {}

    /**
     * @throws WebhookVerificationFailed when the payload is not provably authentic.
     */
    public function handle(WebhookPayload $payload): IngestOutcome
    {
        return $this->ingest->ingest($this->verifier->verify($payload));
    }
}
