---
title: Installation
weight: 1
description: Install via Composer and set the Stripe secret key.
---

# Installation

```bash
composer require cboxdk/laravel-billing-stripe
```

`StripeServiceProvider` is auto-discovered. Set a secret key:

```php
// .env
STRIPE_SECRET=sk_live_...
```

Publish the config to change the endpoint or bind differently:

```bash
php artisan vendor:publish --tag=billing-stripe-config
```

When the key is present, `Cbox\Billing\Payment\Contracts\PaymentGateway` is bound to
`StripePaymentGateway`. When it is absent, the binding is skipped and billing keeps
its default gateway.
