<?php

namespace App\Domains\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
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
            ->select('folio_id')
            ->where('property_id', $property->id)
            ->groupBy('folio_id')
            ->havingRaw('SUM(amount) <> 0')
            ->exists());
    }

    /**
     * Whether any folio of the property is open: its guest is in house or its balance isn't settled.
     * Folios of future reservations are left to the future-reservation guard.
     */
    public static function existFor(Property $property): bool
    {
        if (! $property->exists) {
            return false;
        }

        return self::withBalanceExistFor($property) || Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Folio::query()
            ->where('property_id', $property->id)
            ->whereIn('reservation_id', Reservation::query()->select('id')->where('status', ReservationStatus::CheckedIn))
            ->exists());
    }
}
