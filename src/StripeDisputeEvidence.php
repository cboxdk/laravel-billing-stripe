<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Stripe\Contracts\StripeDisputes;

/**
 * Stripe's dispute evidence vocabulary and the reading of a dispute onto the seam's shape
 * ({@see StripeDisputes}).
 *
 * The fields are Stripe's `evidence` keys. The file fields take a `file_…` id from an upload
 * with the `dispute_evidence` purpose; the rest take text. Shipping fields are left out: they
 * describe a parcel, and nothing this adapter bills is shipped.
 */
final class StripeDisputeEvidence
{
    /** The evidence keys that take text. */
    public const array TEXT_FIELDS = [
        'access_activity_log',
        'billing_address',
        'cancellation_policy_disclosure',
        'cancellation_rebuttal',
        'customer_email_address',
        'customer_name',
        'customer_purchase_ip',
        'duplicate_charge_explanation',
        'duplicate_charge_id',
        'product_description',
        'refund_policy_disclosure',
        'refund_refusal_explanation',
        'service_date',
        'uncategorized_text',
    ];

    /** The evidence keys that take an uploaded file's id. */
    public const array FILE_FIELDS = [
        'cancellation_policy',
        'customer_communication',
        'customer_signature',
        'duplicate_charge_documentation',
        'receipt',
        'refund_policy',
        'service_documentation',
        'uncategorized_file',
    ];

    /** Every evidence key the adapter sends. */
    public const array FIELDS = [...self::TEXT_FIELDS, ...self::FILE_FIELDS];

    /**
     * The keys of `$evidence` Stripe does not take.
     *
     * @param  array<array-key, mixed>  $evidence
     * @return list<string>
     */
    public static function unknown(array $evidence): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($evidence)),
            static fn (string $key): bool => ! in_array($key, self::FIELDS, true),
        ));
    }

    /**
     * A dispute's array form, read onto the seam's shape.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{id: string, status: string, reason: string, amountMinor: int, currency: string, charge: string, paymentIntent: string, dueBy: ?int, hasEvidence: bool, pastDue: bool, submissionCount: int}
     */
    public static function map(array $data): array
    {
        $details = isset($data['evidence_details']) && is_array($data['evidence_details']) ? $data['evidence_details'] : [];
        $dueBy = $details['due_by'] ?? null;
        $count = $details['submission_count'] ?? null;
        $amount = $data['amount'] ?? null;

        return [
            'id' => self::str($data, 'id'),
            'status' => self::str($data, 'status'),
            'reason' => self::str($data, 'reason'),
            'amountMinor' => is_int($amount) ? $amount : 0,
            'currency' => strtoupper(self::str($data, 'currency')),
            'charge' => self::ref($data, 'charge'),
            'paymentIntent' => self::ref($data, 'payment_intent'),
            'dueBy' => is_int($dueBy) ? $dueBy : null,
            'hasEvidence' => ($details['has_evidence'] ?? false) === true,
            'pastDue' => ($details['past_due'] ?? false) === true,
            'submissionCount' => is_int($count) ? $count : 0,
        ];
    }

    /**
     * An id that Stripe may give either as the bare id or as the expanded object.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function ref(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (is_array($value)) {
            return self::str($value, 'id');
        }

        return is_string($value) ? $value : '';
    }

    /** @param  array<array-key, mixed>  $data */
    private static function str(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }
}
