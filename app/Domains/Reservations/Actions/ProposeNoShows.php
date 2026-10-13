<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Support\Audit\Audit;

/**
 * Proposes a no-show for each confirmed reservation that arrives on or before
 * the closing date. Never changes a reservation; one proposal per reservation.
 * Night audit calls it inside its transaction, under the property lock.
 */
class ProposeNoShows
{
    /** @return int proposals made */
    public function handle(Property $property, string $date): int
    {
        $reservations = Reservation::query()->where('property_id', $property->id)
            ->where('status', ReservationStatus::Confirmed)->whereDate('arrival', '<=', $date)
            ->whereNotIn('id', NoShowProposal::query()->select('reservation_id'))
            ->orderBy('id')->get();
        foreach ($reservations as $reservation) {
            $proposal = NoShowProposal::query()->create(['reservation_id' => $reservation->id, 'business_date' => $date, 'status' => 'proposed']);
            Audit::record(null, 'created', $proposal, 'No-show proposed', ['reservation_id' => $reservation->id, 'business_date' => $date]);
        }

        return $reservations->count();
    }
}
