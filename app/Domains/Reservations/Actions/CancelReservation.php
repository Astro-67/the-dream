<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;

class CancelReservation
{
    public function handle(?User $actor, Reservation $reservation, string $reason): Reservation
    {
        return app(TerminateReservation::class)->handle($actor, $reservation, $reason, ReservationStatus::Cancelled);
    }
}
