<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Enums\PaymentIntentStatus;
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

    /**
     * Maps a Stripe payment- or setup-intent status onto the engine's gateway-agnostic
     * {@see PaymentIntentStatus} the frontend drives its element from. `requires_action`
     * (and the pre-confirm `requires_confirmation`) is a live 3-D Secure / SCA challenge;
     * `processing` and a manual-capture `requires_capture` are accepted-and-settling
     * out of band; `requires_payment_method` needs the element to collect a usable method.
     * Any status we do not recognise resolves conservatively to `RequiresPaymentMethod`
     * (collect a method) — it never over-claims a settlement the webhook has not confirmed.
     */
    public function mapIntentStatus(string $status): PaymentIntentStatus
    {
        return match ($status) {
            'succeeded' => PaymentIntentStatus::Succeeded,
            'processing', 'requires_capture' => PaymentIntentStatus::Processing,
            'requires_action', 'requires_confirmation' => PaymentIntentStatus::RequiresAction,
            'canceled' => PaymentIntentStatus::Canceled,
            default => PaymentIntentStatus::RequiresPaymentMethod,
        };
    }
}
