---
title: Status mapping & the SDK seam
weight: 1
description: How the Stripe SDK is isolated and its statuses mapped to a PaymentResult.
---

# Status mapping & the SDK seam

## The seam

`StripePaymentGateway` depends on a small `StripeIntentCreator` interface, not the
Stripe SDK directly:

- `StripeApiIntentCreator` is the real implementation — it wraps `\Stripe\StripeClient`,
  makes the API call, and normalises the result to `{id, status}`. It is deliberately
  thin.
- `FakeStripeIntentCreator` drives the tests.

This keeps the gateway's decision logic (status mapping, error handling) fully
unit-tested without the network, and confines the "verify against the live API"
surface to the thin wrapper.

## Mapping

| Stripe status | `PaymentResult` |
| --- | --- |
| `succeeded` | settled |
| `processing` | pending |
| `requires_action` · `requires_confirmation` · `requires_payment_method` | requires action |
| anything else | failed |

A Stripe API failure is caught and returned as a **failed** result — the gateway
never throws. The Stripe payment-intent id is carried through as the gateway
reference for reconciliation.
