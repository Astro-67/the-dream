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

class TerminateReservation
{
    public function handle(?User $actor, Reservation $reservation, string $reason, ReservationStatus $status): Reservation
    {
        [$event, $refusal, $message] = match ($status) {
            ReservationStatus::Cancelled => ['cancelled', 'Reservation cancellation refused', __('Only confirmed reservations can be cancelled.')],
            ReservationStatus::NoShow => ['no_show_confirmed', 'No-show confirmation refused', __('Only confirmed reservations can become no-shows.')],
            default => ['voided', 'Reservation void refused', __('Only confirmed reservations can be voided.')],
        };

        return Audit::validationRefusal($actor, $reservation, $refusal, fn () => DB::transaction(function () use ($actor, $reservation, $reason, $status, $event, $message) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => $message]);
            }
            $type = RoomType::query()->findOrFail($locked->getAttribute('room_type_id'));
            $dates = iterator_to_array($locked->arrival->daysUntil($locked->departure)->excludeEndDate()->map(fn ($date) => $date->toDateString()));
            app(ReleaseInventory::class)->handle($type, $dates);
            RoomAssignmentNight::query()->where('reservation_id', $locked->id)->delete();
            $locked->update(['status' => $status, 'room_id' => null]);
            app(RecordLifecycleEvent::class)->handle($locked, $event, $actor, ['reason' => $reason]);
            Audit::record($actor, $event, $locked, 'Reservation '.$event, ['reason' => $reason]);

            return $locked;
        }, attempts: 3));
    }
}
