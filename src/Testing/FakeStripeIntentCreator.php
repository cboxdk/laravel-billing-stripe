<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Testing;

use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws, for both
 * the charge and the refund path, with no SDK or network.
 */
class FakeStripeIntentCreator implements StripeIntentCreator
{
    /** @var list<string> the idempotency keys the gateway passed on charge, in order */
    public array $idempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on refund, in order */
    public array $refundIdempotencyKeys = [];

    public function __construct(
        private string $status = 'succeeded',
        private string $id = 'pi_fake',
        private bool $fail = false,
        private string $refundStatus = 'succeeded',
        private string $refundId = 're_fake',
    ) {}

    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array
    {
        $this->idempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new StripeChargeFailed('card_declined');
        }

        return ['id' => $this->id, 'status' => $this->status];
    }

    public function refund(int $amountMinor, string $paymentIntentId, string $idempotencyKey): array
    {
        $this->refundIdempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new StripeChargeFailed('refund_failed');
        }

        return ['id' => $this->refundId, 'status' => $this->refundStatus];
    }
}
