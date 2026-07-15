---
title: Testing
weight: 2
description: Drive the gateway with the fake intent creator and dogfood the shared webhook fakes — no SDK, no network.
---

# Testing

## The gateway

Because the Stripe SDK sits behind the `StripeIntentCreator` seam, you test the
gateway with `FakeStripeIntentCreator` — no network, no keys:

```php
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Stripe\StripePaymentGateway;
use Cbox\Billing\Stripe\Testing\FakeStripeIntentCreator;

$gateway = new StripePaymentGateway(
    new FakeStripeIntentCreator('succeeded', 'pi_test'),
    new FakeSettledPaymentStore,
);

$result = $gateway->charge($intent);
expect($result->isSettled())->toBeTrue();
```

Pass a status (`succeeded`, `processing`, `requires_action`, …) or `fail: true` to
exercise each mapped outcome, including the never-throws failure path, and the refund
path.

## Webhooks — dogfood the shared seam

The webhook path is tested with the fakes the engine ships in
`Cbox\Billing\Payment\Testing`, so your test drives the very same exactly-once ingest
production uses. `FakeWebhookVerifier` stands in for the SDK signature check, and the
real `DefaultWebhookIngest` runs over the shared in-memory stores:

```php
use Cbox\Billing\Payment\Testing\FakeInvoicePaymentApplier;
use Cbox\Billing\Payment\Testing\FakeProcessedEventStore;
use Cbox\Billing\Payment\Testing\FakeSettledPaymentStore;
use Cbox\Billing\Payment\Testing\FakeWebhookVerifier;
use Cbox\Billing\Payment\Webhook\DefaultWebhookIngest;
use Cbox\Billing\Stripe\StripeWebhookHandler;

$applier = new FakeInvoicePaymentApplier;
$handler = new StripeWebhookHandler(
    FakeWebhookVerifier::accepting($event),
    new DefaultWebhookIngest(new FakeProcessedEventStore, new FakeSettledPaymentStore, $applier),
);

$outcome = $handler->handle($payload);
expect($outcome->wasApplied())->toBeTrue()
    ->and($applier->timesPaid($event->reference))->toBe(1);
```

The Stripe-specific `StripeApiWebhookVerifier` normalisation (event → `WebhookEvent`,
amount extraction) is proven against a genuinely-signed payload through the real Stripe
SDK — see `tests/Feature/StripeApiWebhookVerifierTest.php`.
