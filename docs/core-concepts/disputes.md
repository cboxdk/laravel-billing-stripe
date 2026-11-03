---
title: Dispute evidence
weight: 3
description: Read a Stripe dispute, upload evidence files and send the evidence, through a seam of its own.
---

# Dispute evidence

When a customer's bank disputes a payment, Stripe gives the seller a deadline to send evidence.
The adapter reads the dispute and sends the evidence through `StripeDisputes`, a seam of its own,
so a host that only charges never has to implement it.

```php
use Cbox\Billing\Stripe\Contracts\StripeDisputes;

$disputes = app(StripeDisputes::class); // bound when billing-stripe.secret is set

$dispute = $disputes->retrieve('du_…');
// ['id' => 'du_…', 'status' => 'needs_response', 'reason' => 'fraudulent',
//  'amountMinor' => 12500, 'currency' => 'DKK', 'charge' => 'ch_…', 'paymentIntent' => 'pi_…',
//  'dueBy' => 1767225600, 'hasEvidence' => false, 'pastDue' => false, 'submissionCount' => 0]

$receipt = $disputes->uploadEvidenceFile('invoice-42.pdf', $pdfBytes); // 'file_…'

$disputes->submitEvidence('du_…', [
    'receipt' => $receipt,
    'customer_name' => 'Northwind ApS',
    'uncategorized_text' => 'The customer used the service throughout the period.',
]);
```

- `dueBy` is `evidence_details.due_by`: the Unix time the evidence must reach Stripe by.
- `uploadEvidenceFile()` uploads with the `dispute_evidence` purpose. Stripe takes PDF, JPEG and
  PNG.
- `submitEvidence()` takes only Stripe's evidence keys: `StripeDisputeEvidence::TEXT_FIELDS`
  take text and `StripeDisputeEvidence::FILE_FIELDS` take a `file_…` id. Any other key is
  refused before Stripe is called. Pass `submit: false` to store the evidence at Stripe without
  sending it to the bank yet. Once it is submitted, Stripe does not let the evidence change.
- A refusal or a network failure is a `StripeDisputeFailed`.

A host with one Stripe account per environment builds it from that environment's key:
`new StripeApiDisputes(new StripeClient($secret))`.

## Testing

`Cbox\Billing\Stripe\Testing\FakeStripeDisputes` keeps disputes in memory. Seed one with
`add()`, and assert on `uploads` and `submissions`. A submission moves the dispute to
`under_review`, as Stripe does, and a second one is refused. `failNext()` makes the next call
throw.
