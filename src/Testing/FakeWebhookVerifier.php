<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Testing;

use Cbox\Billing\Stripe\Contracts\WebhookVerifier;
use Cbox\Billing\Stripe\Exceptions\WebhookVerificationFailed;
use Cbox\Billing\Stripe\ValueObjects\WebhookEvent;

/**
 * A scripted {@see WebhookVerifier} for tests — returns a pre-built event or, when
 * configured to reject, throws {@see WebhookVerificationFailed} to stand in for a bad
 * signature. No SDK or crypto; the real verification is proven separately against the
 * live Stripe SDK.
 */
class FakeWebhookVerifier implements WebhookVerifier
{
    public function __construct(
        private WebhookEvent $event = new WebhookEvent('evt_fake', 'payment_intent.succeeded', 'DK-000001', 'pi_fake', 'succeeded'),
        private bool $reject = false,
    ) {}

    public function verify(string $payload, string $signatureHeader): WebhookEvent
    {
        if ($this->reject) {
            throw new WebhookVerificationFailed('Invalid Stripe signature.');
        }

        return $this->event;
    }
}
