<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe\Testing;

use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;

/**
 * A scripted intent creator for tests — returns a fixed id/status or throws, for the
 * charge, refund, on/off-session intent, customer, and stored-method paths, with no SDK or
 * network.
 *
 * The stored-method operations behave like a small per-account vault the suite can assert
 * on: the first method attached to an account becomes its default, setDefault reassigns the
 * flag, and detach drops the method (idempotently) — the same observable shape the shared
 * engine fake exposes, so a test reads the real attach/list/default/detach behaviour rather
 * than a canned response. createCustomer mints one `cus_test_…` reference per account and
 * remembers it, and every customer/detach call is recorded for assertions.
 */
class FakeStripeIntentCreator implements StripeIntentCreator
{
    /** @var list<string> the idempotency keys the gateway passed on charge, in order */
    public array $idempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on refund, in order */
    public array $refundIdempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on intent creation, in order */
    public array $intentIdempotencyKeys = [];

    /** @var list<string> the idempotency keys the gateway passed on setup creation, in order */
    public array $setupIdempotencyKeys = [];

    /** @var list<?string> the payment-method ids the gateway passed on intent creation, in order */
    public array $intentPaymentMethodIds = [];

    /** @var array<string, list<array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}>> */
    private array $methods = [];

    /** @var array<string, string> the customer ref minted per account, so a second call re-resolves the same id */
    private array $customers = [];

    /** @var list<array{account: string, email: ?string, name: ?string}> every createCustomer call, in order */
    public array $customerCalls = [];

    /** @var list<array{account: string, methodId: string}> every detachMethod call, in order */
    public array $detachments = [];

    public function __construct(
        private string $status = 'succeeded',
        private string $id = 'pi_fake',
        private bool $fail = false,
        private string $refundStatus = 'succeeded',
        private string $refundId = 're_fake',
        private string $intentStatus = 'succeeded',
    ) {}

    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array
    {
        $this->idempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new StripeChargeFailed('card_declined');
        }

        return ['id' => $this->id, 'status' => $this->status];
    }

    public function refund(int $amountMinor, string $paymentIntentId, string $idempotencyKey): array
    {
        $this->refundIdempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new StripeChargeFailed('refund_failed');
        }

        return ['id' => $this->refundId, 'status' => $this->refundStatus];
    }

    public function createIntent(int $amountMinor, string $currency, string $account, string $reference, string $idempotencyKey, ?string $paymentMethodId): array
    {
        $this->intentIdempotencyKeys[] = $idempotencyKey;
        $this->intentPaymentMethodIds[] = $paymentMethodId;

        if ($this->fail) {
            throw new StripeChargeFailed('intent_failed');
        }

        return ['id' => 'pi_intent', 'status' => $this->intentStatus, 'clientSecret' => 'pi_intent_secret_'.$idempotencyKey];
    }

    public function createSetup(string $account, string $idempotencyKey): array
    {
        $this->setupIdempotencyKeys[] = $idempotencyKey;

        if ($this->fail) {
            throw new StripeChargeFailed('setup_failed');
        }

        return ['id' => 'seti_fake', 'status' => $this->intentStatus, 'clientSecret' => 'seti_secret_'.$idempotencyKey];
    }

    public function listMethods(string $account): array
    {
        return $this->methods[$account] ?? [];
    }

    public function attachMethod(string $account, string $paymentMethodId): array
    {
        if ($this->fail) {
            throw new StripeChargeFailed('attach_failed');
        }

        // The first method attached to an account becomes its default.
        $isDefault = ($this->methods[$account] ?? []) === [];

        $method = [
            'id' => $paymentMethodId,
            'brand' => 'visa',
            'last4' => '4242',
            'expMonth' => 12,
            'expYear' => 2030,
            'isDefault' => $isDefault,
        ];

        $this->methods[$account][] = $method;

        return $method;
    }

    public function setDefaultMethod(string $account, string $paymentMethodId): void
    {
        if ($this->fail) {
            throw new StripeChargeFailed('set_default_failed');
        }

        $this->methods[$account] = array_map(
            static function (array $method) use ($paymentMethodId): array {
                $method['isDefault'] = $method['id'] === $paymentMethodId;

                return $method;
            },
            $this->methods[$account] ?? [],
        );
    }

    public function createCustomer(string $account, ?string $email, ?string $name): string
    {
        $this->customerCalls[] = ['account' => $account, 'email' => $email, 'name' => $name];

        if ($this->fail) {
            throw new StripeChargeFailed('customer_failed');
        }

        // Mint once per account and remember it, so a repeat re-resolves the same reference.
        return $this->customers[$account] ??= 'cus_test_'.$account;
    }

    public function detachMethod(string $account, string $paymentMethodId): void
    {
        $this->detachments[] = ['account' => $account, 'methodId' => $paymentMethodId];

        if ($this->fail) {
            throw new StripeChargeFailed('detach_failed');
        }

        // Idempotent: dropping an already-detached method from the vault is a clean no-op.
        $remaining = array_values(array_filter(
            $this->methods[$account] ?? [],
            static fn (array $method): bool => $method['id'] !== $paymentMethodId,
        ));

        if ($remaining === []) {
            unset($this->methods[$account]);

            return;
        }

        $this->methods[$account] = $remaining;
    }

    /**
     * The customer reference minted for `$account`, or null if none was ever created — lets a
     * test assert the mint-once behaviour without reaching through createCustomer again.
     */
    public function customerFor(string $account): ?string
    {
        return $this->customers[$account] ?? null;
    }
}
