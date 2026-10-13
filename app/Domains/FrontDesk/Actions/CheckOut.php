<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends the stay (PMS-05) and hands the room to housekeeping as dirty.
 */
class CheckOut
{
    public function handle(User $actor, Reservation $reservation): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Check-out refused', fn () => DB::transaction(function () use ($actor, $reservation) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only in-house reservations can be checked out.')]);
            }

            $locked->update(['status' => ReservationStatus::CheckedOut]);
            if ($locked->room_id !== null) {
                app(ClearRoomOccupied::class)->handle($actor, Room::query()->findOrFail($locked->room_id), 'Check-out '.$locked->reservation_number);
            }
            app(RecordLifecycleEvent::class)->handle($locked, 'checked_out', $actor);
            Audit::record($actor, 'checked_out', $locked, 'Reservation checked out');

            return $locked;
        }, attempts: 3));
    }
}
