<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Stripe\Contracts\WebhookVerifier;
use Cbox\Billing\Stripe\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Stripe\ValueObjects\WebhookEvent;
use Stripe\Webhook;
use Throwable;

/**
 * The real webhook verifier: wraps the Stripe SDK's {@see Webhook::constructEvent()}
 * (HMAC-SHA256 over the raw body with the endpoint signing secret, plus a timestamp
 * tolerance to defeat replay) and normalises the verified event. No bespoke crypto —
 * verification is entirely the SDK's. Verify against the live Stripe API before
 * relying on it in production.
 */
readonly class StripeApiWebhookVerifier implements WebhookVerifier
{
    public function __construct(private string $signingSecret) {}

    public function verify(string $payload, string $signatureHeader): WebhookEvent
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $this->signingSecret);
        } catch (Throwable $e) {
            throw new WebhookVerificationFailed($e->getMessage(), previous: $e);
        }

        $object = $event->data->object->toArray();
        $metadata = isset($object['metadata']) && is_array($object['metadata']) ? $object['metadata'] : [];

        return new WebhookEvent(
            eventId: $event->id,
            type: $event->type,
            reference: self::string($metadata, 'reference'),
            gatewayReference: self::string($object, 'id'),
            status: self::string($object, 'status'),
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }
}
