<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Testing;

use Cbox\Billing\Stripe\Contracts\StripeDisputes;
use Cbox\Billing\Stripe\Exceptions\StripeDisputeFailed;
use Cbox\Billing\Stripe\StripeDisputeEvidence;

/**
 * A scripted {@see StripeDisputes} for tests: disputes are seeded with {@see add()}, uploads mint
 * `file_test_…` ids, and a submission moves the dispute to `under_review` the way Stripe does.
 * Every upload and submission is recorded for assertions. It refuses unknown evidence keys
 * exactly like the real one, and {@see failNext()} makes the next call throw.
 */
class FakeStripeDisputes implements StripeDisputes
{
    /** @var array<string, array{id: string, status: string, reason: string, amountMinor: int, currency: string, charge: string, paymentIntent: string, dueBy: ?int, hasEvidence: bool, pastDue: bool, submissionCount: int}> */
    private array $disputes = [];

    /** @var list<array{id: string, name: string, contents: string}> */
    public array $uploads = [];

    /** @var list<array{dispute: string, evidence: array<string, string>, submit: bool}> */
    public array $submissions = [];

    private ?string $failure = null;

    /** Seed a dispute that needs a response, due `$dueBy` (Unix time). */
    public function add(string $id, int $amountMinor = 1000, string $currency = 'DKK', ?int $dueBy = null, string $status = 'needs_response', string $reason = 'fraudulent', string $paymentIntent = '', string $charge = ''): void
    {
        $this->disputes[$id] = [
            'id' => $id,
            'status' => $status,
            'reason' => $reason,
            'amountMinor' => $amountMinor,
            'currency' => strtoupper($currency),
            'charge' => $charge,
            'paymentIntent' => $paymentIntent,
            'dueBy' => $dueBy,
            'hasEvidence' => false,
            'pastDue' => false,
            'submissionCount' => 0,
        ];
    }

    /** Make the next call fail with `$message`. */
    public function failNext(string $message = 'Stripe is unavailable.'): void
    {
        $this->failure = $message;
    }

    public function retrieve(string $disputeId): array
    {
        $this->throwIfScripted();

        return $this->disputes[$disputeId] ?? throw new StripeDisputeFailed('No such dispute: '.$disputeId);
    }

    public function uploadEvidenceFile(string $fileName, string $contents): string
    {
        $this->throwIfScripted();

        $id = 'file_test_'.(count($this->uploads) + 1);
        $this->uploads[] = ['id' => $id, 'name' => $fileName, 'contents' => $contents];

        return $id;
    }

    public function submitEvidence(string $disputeId, array $evidence, bool $submit = true): array
    {
        $unknown = StripeDisputeEvidence::unknown($evidence);

        if ($unknown !== []) {
            throw StripeDisputeFailed::unknownFields($unknown);
        }

        $this->throwIfScripted();
        $dispute = $this->retrieve($disputeId);

        if (! in_array($dispute['status'], ['needs_response', 'warning_needs_response'], true)) {
            throw new StripeDisputeFailed('This dispute is already closed or under review; its evidence can no longer be changed.');
        }

        $this->submissions[] = ['dispute' => $disputeId, 'evidence' => $evidence, 'submit' => $submit];
        $dispute['hasEvidence'] = true;

        if ($submit) {
            $dispute['status'] = $dispute['status'] === 'warning_needs_response' ? 'warning_under_review' : 'under_review';
            $dispute['submissionCount']++;
        }

        return $this->disputes[$disputeId] = $dispute;
    }

    private function throwIfScripted(): void
    {
        if ($this->failure !== null) {
            $message = $this->failure;
            $this->failure = null;

            throw new StripeDisputeFailed($message);
        }
    }
}
