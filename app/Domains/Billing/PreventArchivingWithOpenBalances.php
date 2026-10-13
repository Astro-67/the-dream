<?php

namespace App\Domains\Billing;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

final class PreventArchivingWithOpenBalances implements PreArchiveCheck
{
    public function blockingReason(Property $property): ?string
    {
        return OpenFolios::withBalanceExistFor($property)
            ? __('This property has folios with open balances. Settle them before archiving the property.')
            : null;
    }
}
