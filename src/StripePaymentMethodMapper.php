<?php

declare(strict_types=1);

namespace Cbox\Billing\Stripe;

/**
 * Reads a Stripe PaymentMethod's array form onto the seam's display shape: the brand, the last
 * four digits and the card expiry, never the PAN or the full account number.
 *
 * A card's brand is the network (`visa`, `mastercard`, …). A bank debit has no network, so its
 * brand is the payment method type itself (`sepa_debit`, `us_bank_account`, `bacs_debit`), which
 * no card network shares. Its last four are the account's, and it has no expiry. Any other type
 * is not one an off-session renewal can charge, and is left out of the list.
 */
final class StripePaymentMethodMapper
{
    /** The types an off-session charge can pull: a card and the three bank debits. */
    public const array SUPPORTED_TYPES = ['card', 'sepa_debit', 'us_bank_account', 'bacs_debit'];

    /** @param  array<array-key, mixed>  $data */
    public static function supports(array $data): bool
    {
        return in_array(self::type($data), self::SUPPORTED_TYPES, true);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{id: string, brand: string, last4: string, expMonth: ?int, expYear: ?int, isDefault: bool}
     */
    public static function map(array $data, bool $isDefault): array
    {
        $type = self::type($data);

        // A method without a type (an older API shape) is a card.
        if ($type === '' || $type === 'card') {
            $card = self::arr($data, 'card');

            return [
                'id' => self::str($data, 'id'),
                'brand' => self::str($card, 'brand'),
                'last4' => self::str($card, 'last4'),
                'expMonth' => self::intOrNull($card, 'exp_month'),
                'expYear' => self::intOrNull($card, 'exp_year'),
                'isDefault' => $isDefault,
            ];
        }

        return [
            'id' => self::str($data, 'id'),
            'brand' => $type,
            'last4' => self::str(self::arr($data, $type), 'last4'),
            'expMonth' => null,
            'expYear' => null,
            'isDefault' => $isDefault,
        ];
    }

    /** @param  array<array-key, mixed>  $data */
    public static function type(array $data): string
    {
        return self::str($data, 'type');
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function arr(array $data, string $key): array
    {
        return isset($data[$key]) && is_array($data[$key]) ? $data[$key] : [];
    }

    /** @param  array<array-key, mixed>  $data */
    private static function str(array $data, string $key): string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : '';
    }

    /** @param  array<array-key, mixed>  $data */
    private static function intOrNull(array $data, string $key): ?int
    {
        return isset($data[$key]) && is_int($data[$key]) ? $data[$key] : null;
    }
}
