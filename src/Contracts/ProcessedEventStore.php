<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

/**
 * Records the Stripe event ids that have already been processed, so a retried or
 * replayed webhook delivery is a no-op. The durable implementation dedups via a
 * unique index (`insertOrIgnore`); an in-memory fake backs the tests.
 */
interface ProcessedEventStore
{
    /**
     * Record the event id and report whether it is newly seen. Returns true on the
     * first delivery (process it) and false if the id was already stored (skip —
     * this is a duplicate). Must be atomic so two concurrent deliveries of the same
     * event id cannot both observe true.
     */
    public function remember(string $eventId): bool;
}
