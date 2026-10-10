<?php

namespace App\Domains\Reservations\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class AvailabilityPolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::property() !== null
            && Access::allows($user, OrganizationPermission::ReservationsView, Tenancy::property());
    }
}
