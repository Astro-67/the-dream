<?php

namespace App\Domains\Reservations;

use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Models\Property;

final class PreventArchivingWithFutureReservations implements PreArchiveCheck
{
    public function blockingReason(Property $property): ?string
    {
        return FutureReservations::existFor($property)
            ? __('This property has future confirmed reservations. Cancel or complete them before archiving the property.')
            : null;
    }
}
