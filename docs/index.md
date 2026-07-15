---
title: Overview
weight: 0
description: A Stripe payment-gateway adapter for cboxdk/laravel-billing, with the SDK isolated behind a testable seam.
---

# Cbox Billing — Stripe

`cboxdk/laravel-billing-stripe` implements billing's `PaymentGateway` contract
backed by Stripe. Install it and set a key, and billing's payments route through
Stripe.

## Mental model

- Billing is gateway-agnostic: it charges a `PaymentIntent` and reads a
  `PaymentResult`. This package provides the Stripe implementation.
- The Stripe SDK is isolated behind a small `StripeIntentCreator` seam, so the
  gateway's status-mapping and error handling are unit-tested without the network.
- The gateway **never throws**: a Stripe API failure becomes a failed result.

## Sections

- [Getting started](getting-started/_index.md) — install, configure, test.
- [Core concepts](core-concepts/_index.md) — the seam and status mapping.
