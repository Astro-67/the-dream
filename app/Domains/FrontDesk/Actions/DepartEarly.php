<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Actions\ReleaseInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\DateRange;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shortens an in-house stay to end on the property's current date, releasing
 * the inventory, room nights and price lines of the nights not stayed.
 */
class DepartEarly
{
    public function handle(User $actor, Reservation $reservation): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Early departure refused', fn () => DB::transaction(function () use ($actor, $reservation) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only in-house reservations can depart early.')]);
            }
            $today = BusinessDate::of($locked);
            $previous = $locked->departure->toDateString();
            if ($today < $locked->arrival->toDateString() || $today >= $previous) {
                throw ValidationException::withMessages(['reservation' => __('An early departure must be during the stay with a night left.')]);
            }
            $released = DateRange::dates($today, $locked->departure->subDay()->toDateString());
            app(ReleaseInventory::class)->handle(RoomType::query()->findOrFail($locked->getAttribute('room_type_id')), $released);
            RoomAssignmentNight::query()->where('reservation_id', $locked->id)->whereDate('date', '>=', $today)->delete();
            $locked->priceLines()->whereDate('date', '>=', $today)->delete();
            $locked->update(['departure' => $today]);
            app(RecordLifecycleEvent::class)->handle($locked, 'departed_early', $actor, ['previous_departure' => $previous]);
            Audit::record($actor, 'departed_early', $locked, 'Early departure recorded', ['old' => ['departure' => $previous], 'attributes' => ['departure' => $today]]);

            return $locked;
        }, attempts: 3));
    }
}
