<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Enums\PaymentStatus;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;

/**
 * Maps a Stripe payment-intent status to a billing {@see PaymentResult}. Shared by
 * the inline charge path and the webhook path so both interpret Stripe's statuses
 * identically — the single source of truth for the mapping.
 */
readonly class StripeStatusMapper
{
    public function map(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'succeeded' => PaymentResult::succeeded($gatewayReference),
            'processing' => PaymentResult::pending($gatewayReference),
            'requires_action', 'requires_confirmation', 'requires_payment_method' => new PaymentResult(PaymentStatus::RequiresAction, $gatewayReference),
            default => PaymentResult::failed("Unexpected Stripe status: {$status}"),
        };
    }

    /**
     * Maps a Stripe refund status to a {@see PaymentResult}. Refunds carry a different
     * status vocabulary from charges (`pending` rather than `processing`), so they map
     * separately — a `canceled`/`failed` refund is a failure, `pending` is out-of-band.
     */
    public function mapRefund(string $status, string $gatewayReference): PaymentResult
    {
        return match ($status) {
            'succeeded' => PaymentResult::succeeded($gatewayReference),
            'pending' => PaymentResult::pending($gatewayReference),
            'requires_action' => new PaymentResult(PaymentStatus::RequiresAction, $gatewayReference),
            default => PaymentResult::failed("Unexpected Stripe refund status: {$status}"),
        };
    }
}
