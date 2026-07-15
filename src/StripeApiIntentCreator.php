<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;
use Stripe\StripeClient;
use Throwable;

/**
 * The real Stripe-SDK-backed intent creator. Thin by design: it makes the API call
 * and normalises the result; all decision logic lives in the gateway. Verify
 * against the live Stripe API before relying on it in production.
 */
readonly class StripeApiIntentCreator implements StripeIntentCreator
{
    public function __construct(private StripeClient $client) {}

    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array
    {
        try {
            $intent = $this->client->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'metadata' => ['reference' => $reference],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
        ];
    }

    public function refund(int $amountMinor, string $paymentIntentId, string $idempotencyKey): array
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $paymentIntentId,
                'amount' => $amountMinor,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
        ];
    }
}
