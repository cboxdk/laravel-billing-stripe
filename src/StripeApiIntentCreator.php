<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

use Cbox\Billing\Stripe\Contracts\StripeIntentCreator;
use Cbox\Billing\Stripe\Exceptions\StripeChargeFailed;
use Stripe\StripeClient;
use Throwable;

/**
 * The real Stripe-SDK-backed intent creator. Thin by design: it makes the API call
 * and normalises the result; all decision logic lives in the gateway. Verify
 * against the live Stripe API before relying on it in production.
 */
readonly class StripeApiIntentCreator implements StripeIntentCreator
{
    public function __construct(private StripeClient $client) {}

    public function create(int $amountMinor, string $currency, string $reference, string $idempotencyKey): array
    {
        try {
            $intent = $this->client->paymentIntents->create([
                'amount' => $amountMinor,
                'currency' => strtolower($currency),
                'metadata' => ['reference' => $reference],
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
        ];
    }

    public function refund(int $amountMinor, string $paymentIntentId, string $idempotencyKey): array
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $paymentIntentId,
                'amount' => $amountMinor,
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $refund->id,
            'status' => (string) $refund->status,
        ];
    }

    public function createIntent(int $amountMinor, string $currency, string $account, string $reference, string $idempotencyKey, ?string $paymentMethodId): array
    {
        $params = [
            'amount' => $amountMinor,
            'currency' => strtolower($currency),
            'customer' => $account,
            'metadata' => ['reference' => $reference],
        ];

        if ($paymentMethodId !== null && $paymentMethodId !== '') {
            $params['payment_method'] = $paymentMethodId;
        }

        try {
            $intent = $this->client->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $intent->id,
            'status' => (string) $intent->status,
            'clientSecret' => is_string($intent->client_secret) ? $intent->client_secret : null,
        ];
    }

    public function createSetup(string $account, string $idempotencyKey): array
    {
        try {
            $setup = $this->client->setupIntents->create([
                'customer' => $account,
                'usage' => 'off_session',
            ], ['idempotency_key' => $idempotencyKey]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        return [
            'id' => (string) $setup->id,
            'status' => (string) $setup->status,
            'clientSecret' => is_string($setup->client_secret) ? $setup->client_secret : null,
        ];
    }

    public function listMethods(string $account): array
    {
        try {
            $customer = $this->client->customers->retrieve($account);
            $methods = $this->client->paymentMethods->all(['customer' => $account, 'type' => 'card']);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        $customerData = $customer->toArray();
        $settings = isset($customerData['invoice_settings']) && is_array($customerData['invoice_settings']) ? $customerData['invoice_settings'] : [];
        $defaultId = self::str($settings, 'default_payment_method');

        $mapped = [];

        foreach ($methods->data as $method) {
            $data = $method->toArray();
            $id = self::str($data, 'id');
            $mapped[] = self::mapMethod($data, $id !== '' && $id === $defaultId);
        }

        return $mapped;
    }

    public function attachMethod(string $account, string $paymentMethodId): array
    {
        try {
            $method = $this->client->paymentMethods->attach($paymentMethodId, ['customer' => $account]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }

        // Attaching does not change the account default; a fresh attach is not yet default.
        return self::mapMethod($method->toArray(), false);
    }

    public function setDefaultMethod(string $account, string $paymentMethodId): void
    {
        try {
            $this->client->customers->update($account, [
                'invoice_settings' => ['default_payment_method' => $paymentMethodId],
            ]);
        } catch (Throwable $e) {
            throw new StripeChargeFailed($e->getMessage(), previous: $e);
        }
    }

    /**
     * Normalise a Stripe PaymentMethod's array form onto the seam's display shape,
     * reading only the non-sensitive card fields (brand/last4/expiry) — never the PAN.
     *
     * @param  array<array-key, mixed>  $data
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     */
    private static function mapMethod(array $data, bool $isDefault): array
    {
        $card = isset($data['card']) && is_array($data['card']) ? $data['card'] : [];

        return [
            'id' => self::str($data, 'id'),
            'brand' => self::str($card, 'brand'),
            'last4' => self::str($card, 'last4'),
            'expMonth' => self::intOrNull($card, 'exp_month'),
            'expYear' => self::intOrNull($card, 'exp_year'),
            'isDefault' => $isDefault,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function str(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function intOrNull(array $data, string $key): ?int
    {
        return isset($data[$key]) && is_int($data[$key]) ? $data[$key] : null;
    }
}
