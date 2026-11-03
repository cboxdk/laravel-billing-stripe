<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Stripe\Contracts\StripeDisputes;
use Cbox\Billing\Stripe\Exceptions\StripeDisputeFailed;
use Stripe\StripeClient;
use Throwable;

/**
 * The real Stripe-SDK-backed {@see StripeDisputes}. Thin: it makes the call and normalises the
 * result through {@see StripeDisputeEvidence}. Evidence with a key Stripe does not take is refused
 * here, before anything reaches Stripe.
 */
readonly class StripeApiDisputes implements StripeDisputes
{
    public function __construct(private StripeClient $client) {}

    public function retrieve(string $disputeId): array
    {
        try {
            $dispute = $this->client->disputes->retrieve($disputeId);
        } catch (Throwable $e) {
            throw new StripeDisputeFailed($e->getMessage(), previous: $e);
        }

        return StripeDisputeEvidence::map($dispute->toArray());
    }

    public function uploadEvidenceFile(string $fileName, string $contents): string
    {
        // The SDK uploads from a stream with a path, so the bytes go through a temporary file that
        // is removed again whatever happens.
        $directory = sys_get_temp_dir().'/cbox-dispute-'.bin2hex(random_bytes(8));
        $path = $directory.'/'.self::safeName($fileName);

        try {
            if (! mkdir($directory, 0700) || file_put_contents($path, $contents) === false) {
                throw new StripeDisputeFailed('Could not stage the evidence file for upload.');
            }

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new StripeDisputeFailed('Could not read the staged evidence file.');
            }

            try {
                $file = $this->client->files->create(['purpose' => 'dispute_evidence', 'file' => $handle]);
            } finally {
                fclose($handle);
            }
        } catch (StripeDisputeFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new StripeDisputeFailed($e->getMessage(), previous: $e);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }

            if (is_dir($directory)) {
                rmdir($directory);
            }
        }

        return (string) $file->id;
    }

    public function submitEvidence(string $disputeId, array $evidence, bool $submit = true): array
    {
        $unknown = StripeDisputeEvidence::unknown($evidence);

        if ($unknown !== []) {
            throw StripeDisputeFailed::unknownFields($unknown);
        }

        // Every key was checked against Stripe's vocabulary above, so the evidence has its shape.
        /** @var array{access_activity_log?: string, billing_address?: string, cancellation_policy_disclosure?: string, cancellation_rebuttal?: string, customer_email_address?: string, customer_name?: string, customer_purchase_ip?: string, duplicate_charge_explanation?: string, duplicate_charge_id?: string, product_description?: string, refund_policy_disclosure?: string, refund_refusal_explanation?: string, service_date?: string, uncategorized_text?: string, cancellation_policy?: string, customer_communication?: string, customer_signature?: string, duplicate_charge_documentation?: string, receipt?: string, refund_policy?: string, service_documentation?: string, uncategorized_file?: string} $known */
        $known = $evidence;

        try {
            $dispute = $this->client->disputes->update($disputeId, [
                'evidence' => $known,
                'submit' => $submit,
            ]);
        } catch (Throwable $e) {
            throw new StripeDisputeFailed($e->getMessage(), previous: $e);
        }

        return StripeDisputeEvidence::map($dispute->toArray());
    }

    /** A file name with nothing in it that could leave the staging directory. */
    private static function safeName(string $fileName): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename($fileName)) ?? '';
        $name = trim($name, '.-');

        return $name !== '' ? $name : 'evidence';
    }
}
