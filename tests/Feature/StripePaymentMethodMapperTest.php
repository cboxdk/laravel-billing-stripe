<?php

declare(strict_types=1);

use Cbox\Billing\Stripe\StripePaymentMethodMapper;

it('maps a card to its network, last four and expiry', function () {
    expect(StripePaymentMethodMapper::map([
        'id' => 'pm_card', 'type' => 'card',
        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2034],
    ], true))->toBe([
        'id' => 'pm_card', 'brand' => 'visa', 'last4' => '4242', 'expMonth' => 12, 'expYear' => 2034, 'isDefault' => true,
    ]);
});

it('reads a method with no type as a card', function () {
    expect(StripePaymentMethodMapper::map(['id' => 'pm_old', 'card' => ['brand' => 'amex', 'last4' => '0005']], false)['brand'])->toBe('amex');
});

it('maps each bank debit to its type as the brand, with the account last four and no expiry', function (string $type, string $last4) {
    expect(StripePaymentMethodMapper::map(['id' => 'pm_bank', 'type' => $type, $type => ['last4' => $last4, 'fingerprint' => 'fp']], false))->toBe([
        'id' => 'pm_bank', 'brand' => $type, 'last4' => $last4, 'expMonth' => null, 'expYear' => null, 'isDefault' => false,
    ]);
})->with([
    'SEPA' => ['sepa_debit', '3201'],
    'ACH' => ['us_bank_account', '6789'],
    'Bacs' => ['bacs_debit', '2345'],
]);

it('supports cards and the three bank debits, and nothing an off-session charge cannot pull', function () {
    foreach (['card', 'sepa_debit', 'us_bank_account', 'bacs_debit'] as $type) {
        expect(StripePaymentMethodMapper::supports(['type' => $type]))->toBeTrue();
    }

    expect(StripePaymentMethodMapper::supports(['type' => 'ideal']))->toBeFalse()
        ->and(StripePaymentMethodMapper::supports(['type' => 'link']))->toBeFalse();
});
