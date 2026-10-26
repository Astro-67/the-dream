<?php

namespace App\Domains\Reservations\Policies;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Access;

class ReservationPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::ReservationsView);
    }

    public function overrideBookingRules(User $user): bool
    {
        return Access::inProperty($user, P::PropertiesUpdate);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::ReservationsManage);
    }

    public function assignRoom(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::RoomsAssign, $reservation);
    }

    public function void(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsVoid, $reservation);
    }

    public function amend(User $user, Reservation $reservation): bool
    {
        return $this->cancel($user, $reservation) && ($reservation->room_id === null || $this->assignRoom($user, $reservation));
    }

    public function cancel(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsManage, $reservation);
    }

    public function checkIn(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsManage, $reservation);
    }

    public function checkOut(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsManage, $reservation);
    }

    /** Check out leaving an open balance after departure. */
    public function checkOutWithBalance(User $user, Reservation $reservation): bool
    {
        return $this->checkOut($user, $reservation) && Access::allows($user, P::CheckoutOverrideBalance, $reservation)
            && Property::query()->findOrFail($reservation->property_id)->settings()['payment_settings']['manager_override'];
    }

    public function moveRoom(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsManage, $reservation);
    }

    /** Extend a stay or record an early departure. */
    public function changeStay(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsManage, $reservation);
    }

    public function attachGuest(User $user, Reservation $reservation): bool
    {
        return $this->view($user, $reservation) && Access::allows($user, P::ReservationsManage, $reservation) && Access::allows($user, P::GuestsRegister, $reservation);
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ReservationsView, $reservation);
    }

    public function recoverVfms(User $user, Reservation $reservation): bool
    {
        return $this->view($user, $reservation) && Access::allows($user, P::OutboxRecover, $reservation);
    }
}
