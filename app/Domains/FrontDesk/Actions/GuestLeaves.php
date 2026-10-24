<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records that one guest other than the lead guest has left an in-house stay
 * (Guest departure). The stay and its room stay in-house.
 */
class GuestLeaves
{
    public function handle(User $actor, Reservation $reservation, string $guestId): Guest
    {
        return Audit::validationRefusal($actor, $reservation, 'Guest departure refused', fn () => DB::transaction(function () use ($actor, $reservation, $guestId) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only a guest of an in-house stay can leave.')]);
            }
            $links = ReservationGuest::query()->where('reservation_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $link = $links->firstWhere('guest_id', $guestId) ?? throw ValidationException::withMessages(['guest_id' => __('This guest is not staying on this reservation.')]);
            if ($link->is($links->first())) {
                throw ValidationException::withMessages(['guest_id' => __('The lead guest leaves at check-out.')]);
            }
            if ($link->getAttribute('departed_at') !== null) {
                throw ValidationException::withMessages(['guest_id' => __('This guest has already left.')]);
            }

            $link->update(['departed_at' => now()]);
            app(RecordLifecycleEvent::class)->handle($locked, 'guest_departed', $actor, ['guest_id' => $guestId]);
            Audit::record($actor, 'guest_departed', $locked, 'Guest departure recorded', ['guest_id' => $guestId]);

            return Guest::query()->findOrFail($guestId);
        }, attempts: 3));
    }
}
