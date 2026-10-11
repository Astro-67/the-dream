<?php

namespace App\Domains\Reservations\Policies;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsView, Tenancy::property());
    }

    public function create(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsManage, Tenancy::property());
    }

    public function assignRoom(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::RoomsAssign, $reservation);
    }

    public function void(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsVoid, $reservation);
    }

    public function amend(User $user, Reservation $reservation): bool
    {
        return $this->cancel($user, $reservation) && ($reservation->room_id === null || $this->assignRoom($user, $reservation));
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsManage, $reservation);
    }

    public function attachGuest(User $user, Reservation $reservation): bool
    {
        return $this->view($user, $reservation) && Access::allows($user, P::ReservationsManage, $reservation) && Access::allows($user, P::GuestsRegister, $reservation);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ReservationsView, $reservation);
    }
}
