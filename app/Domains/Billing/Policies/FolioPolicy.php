<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class FolioPolicy
{
    /** Finance's list of open balances after departure. */
    public function viewOpenBalances(User $user): bool
    {
        return Access::inProperty($user, P::FinanceView);
    }

    public function postCharge(User $user, Folio $folio): bool
    {
        return Access::inProperty($user, P::ChargesPost, $folio);
    }

    /** Opening another folio on a reservation is a front-desk billing task, like posting a charge. */
    public function create(User $user, Reservation $reservation): bool
    {
        return Access::inProperty($user, P::ChargesPost, $reservation);
    }

    public function transfer(User $user, Folio $folio): bool
    {
        return $this->postCharge($user, $folio);
    }

    public function reverse(User $user, Folio $folio): bool
    {
        return Access::inProperty($user, P::FinanceCorrect, $folio);
    }

    public function recordPayment(User $user, Folio $folio): bool
    {
        return $this->postCharge($user, $folio);
    }

    public function refund(User $user, Folio $folio): bool
    {
        return Access::inProperty($user, P::PaymentsRefund, $folio);
    }

    public function writeOff(User $user, Folio $folio): bool
    {
        return Access::inProperty($user, P::FoliosWriteOff, $folio);
    }
}
