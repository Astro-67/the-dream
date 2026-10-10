<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignRoom
{
    public function handle(?User $actor, Reservation $reservation, ?string $roomId, bool $recordEvent = true): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Reservation room assignment refused', fn () => DB::transaction(function () use ($actor, $reservation, $roomId, $recordEvent) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['room_id' => __('Only confirmed reservations can change room assignment.')]);
            }
            if ($roomId !== null) {
                $room = Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();
                if ($room->deactivated_at !== null || $room->room_type_id !== $reservation->getAttribute('room_type_id') || $room->outOfServicePeriods()->whereDate('starts_on', '<', $reservation->departure)->whereDate('returns_on', '>', $reservation->arrival)->exists()) {
                    throw ValidationException::withMessages(['room_id' => __('Choose an active room of this room type that is in service for every night.')]);
                }
            }
            $old = $reservation->room_id;
            if ($old === $roomId) {
                return $reservation;
            }
            RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->delete();
            if ($roomId !== null) {
                try {
                    for ($date = $reservation->arrival; $date->lessThan($reservation->departure); $date = $date->addDay()) {
                        RoomAssignmentNight::query()->create(['reservation_id' => $reservation->id, 'room_id' => $roomId, 'date' => $date->toDateString()]);
                    }
                } catch (UniqueConstraintViolationException) {
                    throw ValidationException::withMessages(['room_id' => __('This room is already assigned on one or more nights. Choose another room.')]);
                }
            }
            $reservation->update(['room_id' => $roomId]);
            if ($recordEvent) {
                app(RecordLifecycleEvent::class)->handle($reservation, 'modified', $actor);
            }
            Audit::record($actor, 'updated', $reservation, 'Reservation room assignment changed', ['old' => ['room_id' => $old], 'attributes' => ['room_id' => $roomId]]);

            return $reservation;
        }, attempts: 3));
    }
}
