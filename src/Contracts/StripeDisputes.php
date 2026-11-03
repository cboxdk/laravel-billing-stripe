<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

use Cbox\Billing\Stripe\Exceptions\StripeDisputeFailed;
use Cbox\Billing\Stripe\StripeDisputeEvidence;

/**
 * The seam over Stripe's dispute API: read a dispute, upload a file as evidence, and send the
 * evidence. Kept apart from {@see StripeIntentCreator} so a host that only charges never has to
 * implement it.
 *
 * Every method returns normalised arrays, never SDK objects. A dispute reads as:
 *
 *  - `id`, `status` (Stripe's own word: `warning_needs_response`, `needs_response`,
 *    `under_review`, `won`, `lost`, …), `reason`;
 *  - `amountMinor` and `currency` (upper case);
 *  - `charge` and `paymentIntent`, empty when Stripe gives none;
 *  - `dueBy`, the Unix time evidence must reach Stripe by, or null when no response is due;
 *  - `hasEvidence`, `pastDue` and `submissionCount` from `evidence_details`.
 *
 * @phpstan-type Dispute array{id: string, status: string, reason: string, amountMinor: int, currency: string, charge: string, paymentIntent: string, dueBy: ?int, hasEvidence: bool, pastDue: bool, submissionCount: int}
 */
interface StripeDisputes
{
    /**
     * @return Dispute
     *
     * @throws StripeDisputeFailed
     */
    public function retrieve(string $disputeId): array;

    /**
     * Upload `$contents` as a file with the `dispute_evidence` purpose and return its `file_…` id,
     * for one of the file fields in {@see StripeDisputeEvidence::FILE_FIELDS}. Stripe takes PDF,
     * JPEG and PNG.
     *
     * @throws StripeDisputeFailed
     */
    public function uploadEvidenceFile(string $fileName, string $contents): string;

    /**
     * Send `$evidence` for the dispute. Only the keys in {@see StripeDisputeEvidence::FIELDS} are
     * accepted; anything else is refused before Stripe is called. With `$submit` false Stripe
     * stores the evidence and waits; with true it goes to the bank, and the dispute can no longer
     * be changed.
     *
     * @param  array<string, string>  $evidence
     * @return Dispute
     *
     * @throws StripeDisputeFailed
     */
    public function submitEvidence(string $disputeId, array $evidence, bool $submit = true): array;
}
