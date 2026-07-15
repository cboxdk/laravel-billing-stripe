<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * The seam over the Stripe SDK: create a payment intent and return its id and
 * status. Isolating the SDK behind this makes the gateway's mapping logic fully
 * unit-testable without the network.
 */
interface StripeIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws StripeChargeFailed
     */
    public function create(int $amountMinor, string $currency, string $reference): array;
}
