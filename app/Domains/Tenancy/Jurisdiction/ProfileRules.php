<?php

namespace App\Domains\Tenancy\Jurisdiction;

use App\Support\Money\Currency;

/**
 * The answers for one jurisdiction profile. Only JurisdictionRules picks an
 * implementation; everything else asks JurisdictionRules (ADR 0014).
 */
interface ProfileRules
{
    /**
     * @return non-empty-list<Currency>
     */
    public function allowedCurrencies(): array;

    public function hasZraHotelCode(): bool;
}
