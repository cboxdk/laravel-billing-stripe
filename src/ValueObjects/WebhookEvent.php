<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\ValueObjects;

/**
 * A verified, normalised Stripe webhook event — the SDK's raw event reduced to the
 * few fields the gateway acts on. `eventId` is Stripe's stable event id (`evt_…`),
 * the dedup key; `reference` is our invoice/payment reference echoed back from the
 * intent's metadata; `gatewayReference` is Stripe's own object id (`pi_…`).
 */
readonly class WebhookEvent
{
    public function __construct(
        public string $eventId,
        public string $type,
        public string $reference,
        public string $gatewayReference,
        public string $status,
    ) {}
}
