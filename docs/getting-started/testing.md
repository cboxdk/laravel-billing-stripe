---
title: Testing
weight: 2
description: Drive the gateway with the fake intent creator — no SDK, no network.
---

# Testing

Because the Stripe SDK sits behind the `StripeIntentCreator` seam, you test the
gateway with `FakeStripeIntentCreator` — no network, no keys:

```php
use Cbox\Billing\Stripe\StripePaymentGateway;
use Cbox\Billing\Stripe\Testing\FakeStripeIntentCreator;

$gateway = new StripePaymentGateway(new FakeStripeIntentCreator('succeeded', 'pi_test'));

$result = $gateway->charge($intent);
expect($result->isSettled())->toBeTrue();
```

Pass a status (`succeeded`, `processing`, `requires_action`, …) or `fail: true` to
exercise each mapped outcome, including the never-throws failure path.
