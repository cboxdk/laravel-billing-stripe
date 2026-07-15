<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A {@see PaymentGateway} backed by Stripe. Creates a Stripe payment intent for the
 * amount and maps Stripe's status to a PaymentResult. An API failure becomes a
 * failed result — the gateway never throws.
 */
readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private StripeIntentCreator $creator) {}

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
            );
        } catch (StripeChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return $this->map($result['status'], $result['id']);
    }

    private function map(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'succeeded' => PaymentResult::succeeded($gatewayReference),
            'processing' => PaymentResult::pending($gatewayReference),
            'requires_action', 'requires_confirmation', 'requires_payment_method' => new PaymentResult(PaymentStatus::RequiresAction, $gatewayReference),
            default => PaymentResult::failed("Unexpected Stripe status: {$status}"),
        };
    }
}
