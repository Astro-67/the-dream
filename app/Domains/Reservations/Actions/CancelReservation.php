<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Actions\ProposeFee;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CancelReservation
{
    /** Cancels, and proposes the rate plan's cancellation fee in the same transaction. */
    public function handle(?User $actor, Reservation $reservation, string $reason): Reservation
    {
        return DB::transaction(function () use ($actor, $reservation, $reason) {
            $cancelled = app(TerminateReservation::class)->handle($actor, $reservation, $reason, ReservationStatus::Cancelled);
            app(ProposeFee::class)->handle($actor, $cancelled, 'cancellation');

            return $cancelled;
        });
    }
}
