<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

use Cbox\Billing\Stripe\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Stripe\ValueObjects\WebhookEvent;
use Stripe\Webhook;

/**
 * The seam over Stripe's webhook signature verification. The real implementation
 * wraps the Stripe SDK's {@see Webhook::constructEvent()} (HMAC over the raw
 * body against the endpoint signing secret) — we never hand-roll the crypto — and
 * normalises the verified event. Deny-by-default: an unverified payload throws.
 */
interface WebhookVerifier
{
    /**
     * Verify the raw request body against the `Stripe-Signature` header and return
     * the normalised event.
     *
     * @throws WebhookVerificationFailed when the signature is invalid, stale, or absent
     */
    public function verify(string $payload, string $signatureHeader): WebhookEvent;
}
