# Cbox Billing — Stripe

**`cboxdk/laravel-billing-stripe`** — a Stripe payment-gateway adapter for
[`cboxdk/laravel-billing`](https://github.com/cboxdk/laravel-billing). It
implements billing's `PaymentGateway` contract backed by Stripe; install it and set
a key to route billing's payments through Stripe.

## Install

```bash
composer require cboxdk/laravel-billing-stripe
```

```php
// .env
STRIPE_SECRET=sk_live_...
```

With a key set, the provider binds `Cbox\Billing\Payment\Contracts\PaymentGateway`
to the Stripe gateway. Without one, billing keeps its default (manual) gateway.

## Design

- **SDK isolated behind a seam.** `StripePaymentGateway` depends on a small
  `StripeIntentCreator` interface; the real `StripeApiIntentCreator` wraps the
  Stripe SDK, and a `FakeStripeIntentCreator` drives the tests — so the gateway's
  status-mapping and error handling are fully unit-tested without the network.
- **Never throws.** A Stripe API failure becomes a failed `PaymentResult`; Stripe
  statuses map to `succeeded` / `pending` / `requires_action` / `failed`.

> The SDK wrapper implements Stripe's documented API shape — verify against the live
> Stripe API (and provision real keys) before relying on it in production.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`; `stripe/stripe-php` `^20.3`; `cboxdk/laravel-billing`.

## License

MIT.
