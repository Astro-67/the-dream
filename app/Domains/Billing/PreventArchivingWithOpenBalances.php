<?php

namespace App\Domains\Billing;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

final class PreventArchivingWithOpenBalances implements PreArchiveCheck
{
    public function blockingReason(Property $property): ?string
    {
        if (OpenFolios::withBalanceExistFor($property)) {
            return __('This property has folios with open balances. Settle them before archiving the property.');
        }

        return OpenFolios::heldExtrasExistFor($property)
            ? __('This property has extras waiting for a ZRA receipt. Issue them before archiving the property.')
            : null;
    }
}
