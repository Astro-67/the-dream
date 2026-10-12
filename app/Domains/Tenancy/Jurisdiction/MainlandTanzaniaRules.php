<?php

namespace App\Domains\Tenancy\Jurisdiction;

use App\Support\Money\Currency;

final class MainlandTanzaniaRules implements ProfileRules
{
    /**
     * PRD §6: TZS, USD, EUR.
     *
     * ponytail: fixed list; the PRD marks it configurable, so read it from
     * property settings when a mainland property needs another currency.
     */
    public function allowedCurrencies(): array
    {
        return [Currency::TZS, Currency::USD, Currency::EUR];
    }

    public function hasZraHotelCode(): bool
    {
        return false;
    }

    /** PRD §6: configured by the property. */
    public function requiredRegistrationFields(array $configured): array
    {
        return $configured;
    }
}
