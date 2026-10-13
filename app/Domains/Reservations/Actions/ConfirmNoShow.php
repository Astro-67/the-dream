<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Actions\ProposeFee;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * A manager confirms night audit's proposal: the reservation becomes a no-show,
 * its inventory is released, the rate plan's no-show fee is proposed and a
 * no_show_confirmed lifecycle event is recorded, in one transaction.
 */
class ConfirmNoShow
{
    public function handle(User $actor, NoShowProposal $proposal): Reservation
    {
        return Audit::validationRefusal($actor, $proposal, 'No-show confirmation refused', fn () => DB::transaction(function () use ($actor, $proposal) {
            $proposal = NoShowProposal::lockOpen($proposal);
            $reservation = app(TerminateReservation::class)->handle($actor, $proposal->reservation, 'No-show', ReservationStatus::NoShow);
            app(ProposeFee::class)->handle($actor, $reservation, 'no_show');
            $proposal->resolve($actor, 'confirmed');

            return $reservation;
        }));
    }
}
