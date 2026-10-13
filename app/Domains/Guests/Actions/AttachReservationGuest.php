<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttachReservationGuest
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, Reservation $reservation, array $details): void
    {
        DB::transaction(function () use ($actor, $reservation, $details) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('This guest is in house: add guests through registration.')]);
            }
            $guest = ! empty($details['guest_id']) ? Guest::query()->findOrFail($details['guest_id']) : app(CreateGuest::class)->handle($actor, $details);
            $link = ReservationGuest::query()->firstOrCreate(['reservation_id' => $locked->id, 'guest_id' => $guest->id]);
            if ($link->wasRecentlyCreated) {
                Audit::record($actor, 'guest_attached', $locked, 'Guest attached to reservation', ['guest_id' => $guest->id]);
            }
        });
    }
}
