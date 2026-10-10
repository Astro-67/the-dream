<?php

namespace App\Domains\Reservations\Policies;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class ReservationPolicy
{
    public function create(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsManage, Tenancy::property());
    }

    public function assignRoom(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::RoomsAssign, $reservation);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsView, $reservation);
    }
}
