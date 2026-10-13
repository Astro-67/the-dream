<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\FrontDesk\Registration;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts the stay (PMS-04): a confirmed reservation arriving on the business
 * date, in a ready room, with every guest registered as the jurisdiction requires.
 */
class CheckIn
{
    public function handle(User $actor, Reservation $reservation): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Check-in refused', fn () => DB::transaction(function () use ($actor, $reservation) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            $property = Property::query()->findOrFail($locked->property_id);
            $readiness = app(RoomReadiness::class);
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => __('Only confirmed reservations can be checked in.')]);
            }
            if ($locked->arrival->toDateString() !== $readiness->businessDate($property)) {
                throw ValidationException::withMessages(['reservation' => __('Only reservations arriving on the current business date can be checked in.')]);
            }
            $room = $locked->room_id === null ? null : Room::query()->whereKey($locked->room_id)->lockForUpdate()->firstOrFail();
            if ($room === null) {
                throw ValidationException::withMessages(['room' => __('Assign a room before checking in.')]);
            }
            if (! $readiness->isReady($room)) {
                throw ValidationException::withMessages(['room' => __('Room :number is not ready.', ['number' => $room->getAttribute('number')])]);
            }
            Registration::ensure($property, Guest::query()->whereIn('id', ReservationGuest::query()->select('guest_id')->where('reservation_id', $locked->id))->get());

            $locked->update(['status' => ReservationStatus::CheckedIn]);
            app(SetRoomOccupied::class)->handle($actor, $room, 'Check-in '.$locked->reservation_number);
            app(RecordLifecycleEvent::class)->handle($locked, 'checked_in', $actor);
            Audit::record($actor, 'checked_in', $locked, 'Reservation checked in');

            return $locked;
        }, attempts: 3));
    }
}
