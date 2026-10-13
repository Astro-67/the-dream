<?php

namespace App\Domains\Reservations;

use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

final class FutureReservations
{
    public static function existFor(Property $property): bool
    {
        if (! $property->exists) {
            return false;
        }

        return Tenancy::within(TenantContext::forOrganization($property->organization_id), fn () => Reservation::query()
            ->where('property_id', $property->id)
            ->where('status', ReservationStatus::Confirmed)
            ->whereDate('departure', '>', $property->business_date->toDateString())
            ->exists());
    }
}
