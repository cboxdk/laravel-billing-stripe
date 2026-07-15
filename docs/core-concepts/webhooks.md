---
title: Idempotent webhooks
weight: 2
description: How replayed and retried Stripe webhooks are verified once and applied once.
---

# Idempotent webhooks

Stripe retries webhook deliveries and can replay them, so the same effect must never
be applied twice (no double-settle). `StripeWebhookHandler` makes the webhook path
idempotent through four layers.

## 1. Signature verification (deny-by-default)

`WebhookVerifier` is the seam over Stripe's signature check. The real
`StripeApiWebhookVerifier` wraps the SDK's `\Stripe\Webhook::constructEvent()` —
HMAC-SHA256 over the raw body against the endpoint signing secret, with a timestamp
tolerance against replay. We never hand-roll the crypto. An unverified payload throws
`WebhookVerificationFailed` and never touches state.

Set the signing secret to enable the handler:

```php
// .env
STRIPE_WEBHOOK_SECRET=whsec_...
```

## 2. Event-id dedup

Every verified event carries Stripe's stable event id (`evt_…`). `ProcessedEventStore`
records processed ids behind a unique index (`insertOrIgnore`); a redelivery of an
id already seen is a no-op. `FakeProcessedEventStore` backs the tests.

## 3. Per-reference settle-once

The settle effect is keyed on the invoice/payment **reference** (echoed back from the
intent metadata), not the event, via `SettledPaymentStore`. A different event about
the same object cannot settle it a second time.

## 4. No-op backstop

The inline `charge()` path and the webhook path share the same `SettledPaymentStore`:
`charge()` records the reference as settled the moment it succeeds, so a webhook later
re-confirming that payment sees it is already settled and does nothing.

## Resume-safe charge

The inline charge is created with a scoped external idempotency key
(`reference:amount:currency`) passed straight to Stripe. If the process crashes
between the API call and recording the result, a retry with the same key returns the
original intent instead of creating a second charge.

## Applying the result

`handle()` returns the mapped `PaymentResult` for the host to apply, or `null` when
the delivery is a duplicate or not actionable. The stores default to durable database
implementations (publish the migration with `--tag=billing-stripe-migrations`) so the
guarantees hold across processes and retries.
