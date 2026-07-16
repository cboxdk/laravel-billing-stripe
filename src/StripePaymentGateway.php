<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Payment\Contracts\PaymentGateway;
use Cbox\Billing\Payment\Contracts\SettledPaymentStore;
use Cbox\Billing\Payment\ValueObjects\PaymentIntent;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentRequest;
use Cbox\Billing\Payment\ValueObjects\PaymentIntentResult;
use Cbox\Billing\Payment\ValueObjects\PaymentMethod;
use Cbox\Billing\Payment\ValueObjects\PaymentResult;
use Cbox\Billing\Payment\ValueObjects\RefundIntent;
use Cbox\Billing\Payment\ValueObjects\SetupIntentRequest;
use Cbox\Billing\Payment\ValueObjects\SetupIntentResult;
use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A {@see PaymentGateway} backed by Stripe. Creates a Stripe payment intent for the
 * amount (and refunds a captured amount) and maps Stripe's status to a PaymentResult.
 * An API failure becomes a failed result — the gateway never throws.
 *
 * Idempotency properties that live here:
 *
 *  - The intent is created with a scoped external idempotency key
 *    (`reference:amount:currency`), so a crash-and-retry between the API call and
 *    recording the result cannot double-charge. A refund is scoped by the intent's own
 *    idempotency key so a retry cannot refund twice.
 *  - On a settled charge the reference is recorded in the shared
 *    {@see SettledPaymentStore} — the same settle-once guard the webhook ingest reads,
 *    so a later webhook re-confirming the same payment is a no-op (the backstop).
 */
readonly class StripePaymentGateway implements PaymentGateway
{
    public function __construct(
        private StripeIntentCreator $creator,
        private SettledPaymentStore $settledPayments,
        private string $publishableKey = '',
        private StripeStatusMapper $mapper = new StripeStatusMapper,
    ) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function charge(PaymentIntent $intent): PaymentResult
    {
        try {
            $result = $this->creator->create(
                $intent->amount->minor(),
                $intent->amount->currency(),
                $intent->reference,
                $this->idempotencyKey($intent),
            );
        } catch (StripeChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        $mapped = $this->mapper->map($result['status'], $result['id']);

        if ($mapped->isSettled()) {
            $this->settledPayments->settle($intent->reference);
        }

        return $mapped;
    }

    public function refund(RefundIntent $intent): PaymentResult
    {
        try {
            $result = $this->creator->refund(
                $intent->amount->minor(),
                $intent->originalGatewayReference ?? '',
                $intent->idempotencyKey,
            );
        } catch (StripeChargeFailed $e) {
            return PaymentResult::failed($e->getMessage());
        }

        return $this->mapper->mapRefund($result['status'], $result['id']);
    }

    /**
     * Create an on-session PaymentIntent: the frontend mounts Stripe's element against the
     * returned client secret to confirm (and complete any 3-D Secure challenge). No SDK
     * call is hand-rolled here — the seam creates the intent and the mapper normalises the
     * status. An SDK failure propagates as {@see StripeChargeFailed}: there is no failed
     * intent status to return, so on-session creation surfaces the error to the caller
     * rather than silently claiming a state it did not reach.
     */
    public function createPaymentIntent(PaymentIntentRequest $request): PaymentIntentResult
    {
        $result = $this->creator->createIntent(
            $request->amount->minor(),
            $request->amount->currency(),
            $request->account,
            $request->reference,
            $request->idempotencyKey,
            $request->paymentMethodId,
        );

        return new PaymentIntentResult(
            gateway: $this->name(),
            publishableKey: $this->publishableKey(),
            clientSecret: $result['clientSecret'],
            status: $this->mapper->mapIntentStatus($result['status']),
            reference: $request->reference,
            amount: $request->amount,
        );
    }

    /**
     * Create an off-session SetupIntent so a card is vaulted for later renewals — no charge
     * now. The gateway's own setup-intent handle is echoed as the result reference for
     * reconciliation.
     */
    public function createSetupIntent(SetupIntentRequest $request): SetupIntentResult
    {
        $result = $this->creator->createSetup($request->account, $request->idempotencyKey);

        return new SetupIntentResult(
            gateway: $this->name(),
            publishableKey: $this->publishableKey(),
            clientSecret: $result['clientSecret'],
            status: $this->mapper->mapIntentStatus($result['status']),
            reference: $result['id'],
        );
    }

    /**
     * @return list<PaymentMethod>
     */
    public function paymentMethods(string $account): array
    {
        return array_map(
            $this->toPaymentMethod(...),
            $this->creator->listMethods($account),
        );
    }

    public function attachPaymentMethod(string $account, string $paymentMethodId): PaymentMethod
    {
        return $this->toPaymentMethod($this->creator->attachMethod($account, $paymentMethodId));
    }

    public function setDefaultPaymentMethod(string $account, string $paymentMethodId): void
    {
        $this->creator->setDefaultMethod($account, $paymentMethodId);
    }

    /**
     * @param  array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}  $method
     */
    private function toPaymentMethod(array $method): PaymentMethod
    {
        return new PaymentMethod(
            id: $method['id'],
            brand: $method['brand'],
            last4: $method['last4'],
            expMonth: $method['expMonth'],
            expYear: $method['expYear'],
            isDefault: $method['isDefault'],
        );
    }

    /**
     * The configured publishable key for the frontend element, or null when none is set —
     * the result then carries no key rather than an empty string.
     */
    private function publishableKey(): ?string
    {
        return $this->publishableKey !== '' ? $this->publishableKey : null;
    }

    /**
     * Scoped external idempotency key: the reference already encodes the billing
     * period for recurring charges, and the amount pins it per one-off charge, so a
     * safe retry resolves to the same Stripe intent rather than a second charge.
     */
    private function idempotencyKey(PaymentIntent $intent): string
    {
        return sprintf(
            'cbx-%s-%d-%s',
            $intent->reference,
            $intent->amount->minor(),
            $intent->amount->currency(),
        );
    }
}
