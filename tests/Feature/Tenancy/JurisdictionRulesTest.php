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
