<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * The seam over the Stripe SDK: create a payment intent and return its id and
 * status. Isolating the SDK behind this makes the gateway's mapping logic fully
 * unit-testable without the network.
 *
 * `$idempotencyKey` is a caller-scoped external idempotency key passed straight to
 * Stripe: if the process crashes between the API call and recording its result, a
 * retry with the same key returns the original intent instead of creating a second
 * charge, so the create step is resume-safe.
 */
interface StripeIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws StripeChargeFailed
     */
    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array;
}
