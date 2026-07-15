---
title: Idempotent webhooks
weight: 2
description: How replayed and retried Stripe webhooks are verified once and applied once on the shared billing seam.
---

# Idempotent webhooks

Stripe retries webhook deliveries and can replay them, so the same effect must never
be applied twice (no double-settle). This adapter no longer carries its own webhook
contracts: it plugs into the **canonical webhook seam** shipped by
`cboxdk/laravel-billing`, contributing only the Stripe-specific signature check and a
pair of durable stores. The engine owns the exactly-once apply.

## 1. Signature verification (deny-by-default)

`StripeApiWebhookVerifier` implements the engine's shared
`Cbox\Billing\Payment\Contracts\WebhookVerifier`. It takes a `WebhookPayload` (the raw
body plus headers), reads the `Stripe-Signature` header, and wraps the SDK's
`\Stripe\Webhook::constructEvent()` — HMAC-SHA256 over the raw body against the
endpoint signing secret, with a timestamp tolerance against replay. We never hand-roll
the crypto. A missing signature or a body the SDK cannot verify throws the shared
`WebhookVerificationFailed` and never becomes an event.

On success it returns the engine's gateway-agnostic `WebhookEvent`, mapping Stripe's
event onto the narrow `WebhookEventType`: `payment_intent.succeeded` →
`PaymentSettled`; `payment_intent.payment_failed` / `.canceled` → `PaymentFailed`;
every other authentic event → `PaymentPending` (recorded, but moving no money).

Set the signing secret to bind the verifier:

```php
// .env
STRIPE_WEBHOOK_SECRET=whsec_...
```

## 2. Exactly-once ingest (the engine's `WebhookIngest`)

`StripeWebhookHandler` is a thin composition — verify, then ingest:

```php
$outcome = $handler->handle($payload); // $payload is a WebhookPayload built from the request
```

It hands the verified `WebhookEvent` to the engine's `WebhookIngest`, which applies the
paid effect **exactly once per invoice/payment reference** and returns an
`IngestOutcome` (`Applied`, `AlreadySettled`, `DuplicateEvent`, or `Ignored`). Three
guards live inside the shared ingest, not in this adapter:

- **Event-id dedup.** Stripe's stable event id (`evt_…`) is the first-sight key; a
  redelivery of an id already seen is a no-op.
- **Per-reference settle-once.** The paid effect is keyed on the invoice/payment
  reference, so two different events that both mean "invoice X paid" settle X once.
- **Crash-safe ordering.** The effect is applied before the settle claim and event id
  are recorded, so a crash mid-apply persists nothing and the redelivery re-applies
  exactly once.

## 3. Durable stores the adapter contributes

The adapter overrides the engine's zero-config in-memory defaults with durable
database implementations of the shared `ProcessedEventStore` and `SettledPaymentStore`
(both enforced by a unique index via `insertOrIgnore`), so the ingest's guarantees hold
across processes and retries. Publish the migration with
`--tag=billing-stripe-migrations`.

The host binds its own `InvoicePaymentApplier` (the seam that writes the invoice's paid
state); in production that write commits in the same transaction as the settle claim.

## No-op backstop with the inline charge path

The inline `charge()` path and the webhook ingest share the same shared
`SettledPaymentStore`: `charge()` claims the reference the moment it succeeds, so a
webhook later re-confirming that payment sees it is already settled and the ingest
returns `AlreadySettled`.

## Resume-safe charge

The inline charge is created with a scoped external idempotency key
(`reference:amount:currency`) passed straight to Stripe. If the process crashes between
the API call and recording the result, a retry with the same key returns the original
intent instead of creating a second charge.
