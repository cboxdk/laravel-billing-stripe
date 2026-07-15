<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Stripe\Contracts\SettledPaymentStore;
use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A {@see PaymentGateway} backed by Stripe. Creates a Stripe payment intent for the
 * amount and maps Stripe's status to a PaymentResult. An API failure becomes a
 * failed result — the gateway never throws.
 *
 * Two idempotency properties live here:
 *
 *  - The intent is created with a scoped external idempotency key
 *    (`reference:amount:currency`), so a crash-and-retry between the API call and
 *    recording the result cannot double-charge.
 *  - On a settled result the reference is recorded in the {@see SettledPaymentStore},
 *    so a later webhook re-confirming the same payment is a no-op (the backstop).
 */
readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(
        private StripeIntentCreator $creator,
        private SettledPaymentStore $settledPayments,
        private StripeStatusMapper $mapper = new StripeStatusMapper,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        try {
            $result = $this->creator->create(
                $intent->amount->minor(),
                $intent->amount->currency(),
                $intent->reference,
                $this->idempotencyKey($intent),
            );
        } catch (StripeChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        $mapped = $this->mapper->map($result['status'], $result['id']);

        if ($mapped->isSettled()) {
            $this->settledPayments->markSettled($intent->reference);
        }

        return $mapped;
    }

    /**
     * Scoped external idempotency key: the reference already encodes the billing
     * period for recurring charges, and the amount pins it per one-off charge, so a
     * safe retry resolves to the same Stripe intent rather than a second charge.
     */
    private function idempotencyKey(PaymentIntent $intent): string
    {
        return sprintf(
            'cbx-%s-%d-%s',
            $intent->reference,
            $intent->amount->minor(),
            $intent->amount->currency(),
        );
    }
}
