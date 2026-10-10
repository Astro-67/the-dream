<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Inventory\Actions\ReleaseInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidReservation
{
    public function handle(?User $actor, Reservation $reservation, string $reason): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Reservation void refused', fn () => DB::transaction(function () use ($actor, $reservation, $reason) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => __('Only confirmed reservations can be voided.')]);
            }
            $type = RoomType::query()->findOrFail($locked->getAttribute('room_type_id'));
            $dates = iterator_to_array($locked->arrival->daysUntil($locked->departure)->excludeEndDate()->map(fn ($date) => $date->toDateString()));
            app(ReleaseInventory::class)->handle($type, $dates);
            RoomAssignmentNight::query()->where('reservation_id', $locked->id)->delete();
            $locked->update(['status' => ReservationStatus::Void, 'room_id' => null]);
            app(RecordLifecycleEvent::class)->handle($locked, 'voided', $actor, ['reason' => $reason]);
            Audit::record($actor, 'voided', $locked, 'Reservation voided', ['reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
