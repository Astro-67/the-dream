<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Inventory\Actions\TakeInventory;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lengthens an in-house stay: takes inventory and the assigned room for the
 * new nights under booking's locks, and prices them from the rate plan.
 */
class ExtendStay
{
    public function handle(User $actor, Reservation $reservation, string $departure): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Stay extension refused', fn () => DB::transaction(function () use ($actor, $reservation, $departure) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only in-house reservations can be extended.')]);
            }
            $previous = $locked->departure->toDateString();
            if ($departure <= $previous) {
                throw ValidationException::withMessages(['departure' => __('An extension must depart after :date.', ['date' => $previous])]);
            }
            $type = RoomType::query()->findOrFail($locked->getAttribute('room_type_id'));
            $plan = RatePlan::query()->findOrFail($locked->getAttribute('rate_plan_id'));
            // ponytail: stay restrictions (minimum stay, closed to departure) are not re-checked for extensions.
            $quote = app(StayPricing::class)->quote($plan, $type, $previous, $departure, $locked->adults, $locked->children);
            if ($quote->unquotable !== null) {
                throw ValidationException::withMessages(['departure' => $quote->unquotable]);
            }
            $dates = array_column($quote->nights, 'date');
            try {
                app(TakeInventory::class)->handle($type, $dates);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['departure' => __('No rooms are left for every new night.')]);
            }
            $room = Room::query()->whereKey($locked->room_id)->lockForUpdate()->firstOrFail();
            if ($room->outOfServicePeriods()->whereDate('starts_on', '<', $departure)->whereDate('returns_on', '>', $previous)->exists()) {
                throw ValidationException::withMessages(['departure' => __('Room :number is out of order during the new nights.', ['number' => $room->getAttribute('number')])]);
            }
            try {
                foreach ($dates as $date) {
                    RoomAssignmentNight::query()->create(['reservation_id' => $locked->id, 'room_id' => $room->id, 'date' => $date]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['departure' => __('Room :number is already assigned on one or more new nights.', ['number' => $room->getAttribute('number')])]);
            }
            foreach ($quote->nights as $night) {
                $locked->priceLines()->create(['date' => $night['date'], 'amount' => $night['total'], 'currency' => $quote->currency->value, 'rate_plan_id' => $plan->id]);
            }
            $locked->update(['departure' => $departure]);
            app(RecordLifecycleEvent::class)->handle($locked, 'extended', $actor, ['previous_departure' => $previous]);
            Audit::record($actor, 'extended', $locked, 'Stay extended', ['old' => ['departure' => $previous], 'attributes' => ['departure' => $departure]]);

            return $locked;
        }, attempts: 3));
    }
}
