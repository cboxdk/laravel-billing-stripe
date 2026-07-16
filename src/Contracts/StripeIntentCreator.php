<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Contracts;

use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * The seam over the Stripe SDK: create a payment intent for a charge, refund a
 * previously-captured amount, and the client-side intent / stored-method operations the
 * embedded (SCA-aware) integration needs. Isolating the SDK behind this makes the
 * gateway's mapping logic fully unit-testable without the network — no SDK call is ever
 * hand-rolled in the gateway class.
 *
 * `$idempotencyKey` is a caller-scoped external idempotency key passed straight to
 * Stripe: if the process crashes between the API call and recording its result, a
 * retry with the same key returns the original intent (or refund) instead of creating a
 * second charge, so both steps are resume-safe.
 *
 * The intent/method operations return normalised arrays (never SDK objects) so the
 * gateway owns the mapping onto the engine's value objects; card data (PAN/CVC) never
 * crosses this seam — only the non-sensitive display fields.
 */
interface StripeIntentCreator
{
    /**
     * @return array{id: string, status: string}
     *
     * @throws StripeChargeFailed
     */
    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array;

    /**
     * Refund `$amountMinor` against the original payment intent (`pi_…`), scoped by the
     * idempotency key so a retry or a re-delivered webhook collapses to one refund.
     *
     * @return array{id: string, status: string}
     *
     * @throws StripeChargeFailed
     */
    public function refund(int $amountMinor, string $paymentIntentId, string $idempotencyKey): array;

    /**
     * Create an ON-SESSION PaymentIntent for `$account` (the Stripe customer) charging
     * `$amountMinor`, returning the id, lifecycle status and `client_secret` the frontend
     * confirms its element against. When `$paymentMethodId` is set the intent is created
     * against that already-saved method; otherwise the element collects one. The scoped
     * `$idempotencyKey` collapses a retried creation to a single Stripe intent.
     *
     * @return array{id: string, status: string, clientSecret: ?string}
     *
     * @throws StripeChargeFailed
     */
    public function createIntent(int $amountMinor, string $currency, string $account, string $reference, string $idempotencyKey, ?string $paymentMethodId): array;

    /**
     * Create an OFF-SESSION SetupIntent for `$account` (no charge) so a method can be
     * vaulted for later renewals, returning the id, status and `client_secret`. Scoped by
     * `$idempotencyKey` so a retry collapses to one setup intent.
     *
     * @return array{id: string, status: string, clientSecret: ?string}
     *
     * @throws StripeChargeFailed
     */
    public function createSetup(string $account, string $idempotencyKey): array;

    /**
     * The card payment methods saved for `$account`, with the account's default (the
     * customer's `invoice_settings.default_payment_method`) flagged.
     *
     * @return list<array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}>
     *
     * @throws StripeChargeFailed
     */
    public function listMethods(string $account): array;

    /**
     * Attach `$paymentMethodId` to `$account` and return its normalised display fields.
     * Attaching does not change the default.
     *
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     *
     * @throws StripeChargeFailed
     */
    public function attachMethod(string $account, string $paymentMethodId): array;

    /**
     * Make `$paymentMethodId` the default off-session method for `$account` (writes the
     * customer's `invoice_settings.default_payment_method`). The method must be attached.
     *
     * @throws StripeChargeFailed
     */
    public function setDefaultMethod(string $account, string $paymentMethodId): void;

    /**
     * Create the Stripe customer that saved methods and off-session charges attach to,
     * stamping `metadata.account => $account` so the object reconciles back to the host
     * account from the dashboard, and return its `cus_…` id. `$email`/`$name` are set only
     * when provided. An SDK failure surfaces: a customer that was never created must not be
     * returned as an id.
     *
     * @throws StripeChargeFailed
     */
    public function createCustomer(string $account, ?string $email, ?string $name): string;

    /**
     * Detach `$paymentMethodId` from its customer so it can no longer be charged. Idempotent:
     * if Stripe reports the method is already detached / not attached, that is treated as
     * success and swallowed — any other failure surfaces. `$account` is advisory (Stripe
     * detaches globally), kept for the seam shape and auditing.
     *
     * @throws StripeChargeFailed
     */
    public function detachMethod(string $account, string $paymentMethodId): void;
}
