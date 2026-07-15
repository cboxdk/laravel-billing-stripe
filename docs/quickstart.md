---
title: Quickstart
weight: 1
description: Install, set a Stripe key, and route billing payments through Stripe.
---

# Quickstart

```bash
composer require cboxdk/laravel-billing-stripe
```

```php
// .env
STRIPE_SECRET=sk_live_...
```

With a key set, `Cbox\Billing\Payment\Contracts\PaymentGateway` resolves to the
Stripe gateway. Charge as usual through billing:

```php
use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Money\Money;

$result = app(PaymentGateway::class)->charge(
    new PaymentIntent('pi_1', Money::ofMinor(12500, 'EUR'), 'DK-000001'),
);

$result->isSettled();        // true when Stripe reports succeeded
$result->gatewayReference;   // the Stripe payment-intent id, for reconciliation
```

Without a key the package stays out of the way and billing keeps its default
(manual) gateway.
