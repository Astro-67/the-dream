<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Posting;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Asks whether a property still has folios that aren't settled (glossary: Folio).
 */
final class OpenFolios
{
    /** Whether any folio of the property has a non-zero balance. */
    public static function withBalanceExistFor(Property $property): bool
    {
        if (! $property->exists) {
            return false;
        }

        return Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Posting::query()
            ->where('property_id', $property->id)
            ->groupBy('folio_id')
            ->havingRaw('SUM(amount) <> 0')
            ->exists());
    }
}
