<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Inventory\Actions\ReleaseInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CancelReservation
{
    public function handle(?User $actor, Reservation $reservation, string $reason): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Reservation cancellation refused', fn () => DB::transaction(function () use ($actor, $reservation, $reason) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => __('Only confirmed reservations can be cancelled.')]);
            }
            $type = RoomType::query()->findOrFail($locked->getAttribute('room_type_id'));
            $dates = iterator_to_array($locked->arrival->daysUntil($locked->departure)->excludeEndDate()->map(fn ($date) => $date->toDateString()));
            app(ReleaseInventory::class)->handle($type, $dates);
            $locked->update(['status' => ReservationStatus::Cancelled]);
            app(RecordLifecycleEvent::class)->handle($locked, 'cancelled', $actor, ['reason' => $reason]);
            Audit::record($actor, 'cancelled', $locked, 'Reservation cancelled', ['reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
