<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Money\Money;
use Cbox\Billing\Payment\Contracts\WebhookVerifier;
use Cbox\Billing\Payment\Enums\WebhookEventType;
use Cbox\Billing\Payment\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Payment\ValueObjects\WebhookEvent;
use Cbox\Billing\Payment\ValueObjects\WebhookPayload;
use Stripe\Webhook;
use Throwable;

/**
 * The Stripe-backed {@see WebhookVerifier}: wraps the Stripe SDK's
 * {@see Webhook::constructEvent()} (HMAC-SHA256 over the raw body with the endpoint
 * signing secret, plus a timestamp tolerance to defeat replay) and normalises the
 * verified event onto the engine's gateway-agnostic {@see WebhookEvent}. No bespoke
 * crypto — verification is entirely the SDK's. Deny-by-default: a missing signature
 * header or a body the SDK cannot verify throws {@see WebhookVerificationFailed}, so an
 * unverified payload never becomes an event. Verify against the live Stripe API before
 * relying on it in production.
 */
readonly class StripeApiWebhookVerifier implements WebhookVerifier
{
    /**
     * Currency for the placeholder zero amount carried by non-settlement events (which
     * move no money, so the ingest never reads their amount). A genuine settlement
     * always carries its own currency and never reaches this fallback.
     */
    private const PLACEHOLDER_CURRENCY = 'EUR';

    public function __construct(private string $signingSecret) {}

    public function verify(WebhookPayload $payload): WebhookEvent
    {
        $signature = $payload->header('Stripe-Signature');

        if ($signature === null || $signature === '') {
            throw WebhookVerificationFailed::unsigned();
        }

        try {
            $event = Webhook::constructEvent($payload->body, $signature, $this->signingSecret);
        } catch (Throwable $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        $object = $event->data->object->toArray();
        $metadata = isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : [];

        return new WebhookEvent(
            id: $event->id,
            type: self::mapType($event->type),
            reference: self::string($metadata, 'reference'),
            amount: self::amount($object),
        );
    }

    /**
     * Map Stripe's payment-intent lifecycle event onto the engine's narrow event type.
     * Only `payment_intent.succeeded` carries the paid effect; an explicit failure or
     * cancellation maps to a failure notice; every other authentic event (processing,
     * requires_action, and anything else the endpoint receives) maps to a pending
     * notice — recorded and deduped by the ingest, but moving no money.
     */
    private static function mapType(string $stripeType): WebhookEventType
    {
        return match ($stripeType) {
            'payment_intent.succeeded' => WebhookEventType::PaymentSettled,
            'payment_intent.payment_failed', 'payment_intent.canceled' => WebhookEventType::PaymentFailed,
            default => WebhookEventType::PaymentPending,
        };
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private static function amount(array $object): Money
    {
        $currency = strtoupper(self::string($object, 'currency'));

        if ($currency === '') {
            return Money::zero(self::PLACEHOLDER_CURRENCY);
        }

        return Money::ofMinor(self::int($object, 'amount'), $currency);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function int(array $data, string $key): int
    {
        return isset($data[$key]) && is_int($data[$key]) ? $data[$key] : 0;
    }
}
