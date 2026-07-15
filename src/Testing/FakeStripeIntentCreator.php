<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Testing;

use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws,
 * with no SDK or network.
 */
class FakeStripeIntentCreator implements StripeIntentCreator
{
    public function __construct(
        private string $status = 'succeeded',
        private string $id = 'pi_fake',
        private bool $fail = false,
    ) {}

    public function create(int $amountMinor, string $currency, string $reference): array
    {
        if ($this->fail) {
            throw new StripeChargeFailed('card_declined');
        }

        return ['id' => $this->id, 'status' => $this->status];
    }
}
