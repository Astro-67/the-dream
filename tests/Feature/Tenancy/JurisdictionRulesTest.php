<?php

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;

function propertyUnder(JurisdictionProfile $profile): Property
{
    return new Property(['jurisdiction_profile' => $profile]);
}

test('a Zanzibar property may sell only in TZS, USD and EUR', function () {
    expect(JurisdictionRules::allowedCurrencies(propertyUnder(JurisdictionProfile::Zanzibar)))
        ->toBe([Currency::TZS, Currency::USD, Currency::EUR]);
});

test('a mainland property may sell in TZS, USD and EUR', function () {
    expect(JurisdictionRules::allowedCurrencies(propertyUnder(JurisdictionProfile::MainlandTanzania)))
        ->toBe([Currency::TZS, Currency::USD, Currency::EUR]);
});

test('a property may change to any jurisdiction profile while nothing depends on its current one', function (JurisdictionProfile $from, JurisdictionProfile $to) {
    JurisdictionRules::ensureProfileCanChange(propertyUnder($from), $to);
})->throwsNoExceptions()->with([
    'Zanzibar to mainland' => [JurisdictionProfile::Zanzibar, JurisdictionProfile::MainlandTanzania],
    'mainland to Zanzibar' => [JurisdictionProfile::MainlandTanzania, JurisdictionProfile::Zanzibar],
]);

test('a Zanzibar property requires document type, number and nationality whatever it configured', function () {
    expect(JurisdictionRules::requiredRegistrationFields(new Property(['jurisdiction_profile' => JurisdictionProfile::Zanzibar, 'registration_fields' => ['expiry']])))
        ->toBe(['type', 'number', 'nationality']);
});

test('a mainland property requires only the registration fields it configured', function () {
    expect(JurisdictionRules::requiredRegistrationFields(propertyUnder(JurisdictionProfile::MainlandTanzania)))->toBe([])
        ->and(JurisdictionRules::requiredRegistrationFields(new Property(['jurisdiction_profile' => JurisdictionProfile::MainlandTanzania, 'registration_fields' => ['number', 'type']])))
        ->toBe(['number', 'type']);
});
