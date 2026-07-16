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
- **Idempotent webhooks on the shared seam.** `StripeApiWebhookVerifier` implements
  billing's canonical `Cbox\Billing\Payment\Contracts\WebhookVerifier`: it proves the
  Stripe signature via the SDK (deny-by-default) and normalises the delivery onto the
  engine's shared `WebhookEvent`. `StripeWebhookHandler` then hands that event to the
  engine's own `WebhookIngest`, which applies the paid effect to the invoice exactly
  once per reference — collapsing gateway re-deliveries and crash-retries. The adapter
  only overrides the shared dedup/settle stores with durable database implementations;
  it owns no webhook contracts of its own. Charges carry a scoped external idempotency
  key so a crash-and-retry never double-charges. Set `STRIPE_WEBHOOK_SECRET` to enable
  it. See [docs/core-concepts/webhooks.md](docs/core-concepts/webhooks.md).

> The SDK wrapper implements Stripe's documented API shape — verify against the live
> Stripe API (and provision real keys) before relying on it in production.

## Running the live integration tests

The default suite proves the gateway against an in-memory Stripe fake. A separate
`integration` suite (`tests/Integration/StripeLiveTest.php`) drives the **real** Stripe
SDK path end-to-end against **Stripe test mode**. It is gated on a dedicated
`STRIPE_TEST_SECRET` (never `STRIPE_SECRET`, so it can't collide with a production key):
without it the suite **skips cleanly**, so it is excluded from the default run and from CI.

```bash
STRIPE_TEST_SECRET=sk_test_... vendor/bin/pest --group=integration
```

It hits Stripe test mode only — using Stripe's canned test methods (`pm_card_visa`) and
tiny test-currency amounts, never real card data — and creates then removes throwaway test
objects (customer, setup/payment intents, refund) as it exercises the full stored-customer
and payment-method lifecycle. Nothing is written to the repo and no key is committed.

## Requirements

PHP `^8.4`; Laravel `^12 || ^13`; `stripe/stripe-php` `^20.3`; `cboxdk/laravel-billing`.

## License

MIT.
