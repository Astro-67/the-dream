<?php

namespace App\Domains\Tenancy\Jurisdiction;

use App\Support\Money\Currency;

final class ZanzibarRules implements ProfileRules
{
    /** PRD §6: ZRA accepts only these. */
    public function allowedCurrencies(): array
    {
        return [Currency::TZS, Currency::USD, Currency::EUR];
    }

    public function hasZraHotelCode(): bool
    {
        return true;
    }

    public function configuresRegistrationFields(): bool
    {
        return false;
    }

    /** PRD §6: every guest, any document type. */
    public function requiredRegistrationFields(array $configured): array
    {
        return ['type', 'number', 'nationality'];
    }

    /** VFMS has no path for cancellation or no-show fees (glossary: Fiscal exception). */
    public function marksFeesAsFiscalExceptions(): bool
    {
        return true;
    }

    /** ZRA requires every Zanzibar hotel to file stays with VFMS. */
    public function usesVfms(): bool
    {
        return true;
    }
}
