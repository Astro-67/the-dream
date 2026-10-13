<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Housekeeping\Actions\SetRoomOccupied;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Actions\RetakeInventory;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\DateRange;
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
 * Moves an in-house guest to another ready room for the remaining nights. Prices
 * are kept unless $reprice, which re-quotes the remaining nights on the new room type.
 */
class MoveRoom
{
    public function handle(User $actor, Reservation $reservation, string $roomId, bool $reprice = false): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Room move refused', fn () => DB::transaction(function () use ($actor, $reservation, $roomId, $reprice) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only checked-in reservations can move room.')]);
            }
            $room = Room::query()->whereKey($roomId)->lockForUpdate()->firstOrFail();
            $readiness = app(RoomReadiness::class);
            $today = BusinessDate::forPropertyId($room->property_id);
            $last = $locked->departure->subDay()->toDateString();
            if ($last < $today) {
                throw ValidationException::withMessages(['reservation' => __('No nights remain on this stay.')]);
            }
            $dates = DateRange::dates(max($today, $locked->arrival->toDateString()), $last);
            if ($room->id === $locked->room_id || $room->deactivated_at !== null || ! $readiness->isReady($room)
                || $room->outOfServicePeriods()->whereDate('starts_on', '<=', $last)->whereDate('returns_on', '>', $dates[0])->exists()) {
                throw ValidationException::withMessages(['room_id' => __('Room :number is not ready.', ['number' => $room->getAttribute('number')])]);
            }
            $fromRoom = Room::query()->findOrFail($locked->room_id);
            $fromType = $locked->getAttribute('room_type_id');
            if ($room->room_type_id !== $fromType) {
                app(RetakeInventory::class)->handle($fromType, $dates, $room->room_type_id, $dates);
            }
            RoomAssignmentNight::query()->where('reservation_id', $locked->id)->whereDate('date', '>=', $dates[0])->delete();
            try {
                foreach ($dates as $date) {
                    RoomAssignmentNight::query()->create(['reservation_id' => $locked->id, 'room_id' => $room->id, 'date' => $date]);
                }
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['room_id' => __('This room is already assigned on one or more nights. Choose another room.')]);
            }
            if ($reprice) {
                $this->reprice($locked, RoomType::query()->findOrFail($room->room_type_id), $dates);
            }
            $locked->update(['room_id' => $room->id, 'room_type_id' => $room->room_type_id]);
            app(ClearRoomOccupied::class)->handle($actor, $fromRoom, 'Room move '.$locked->reservation_number);
            app(SetRoomOccupied::class)->handle($actor, $room, 'Room move '.$locked->reservation_number);
            $details = ['from_room_id' => $fromRoom->id, 'from_room_type_id' => $fromType, 'pricing' => $reprice ? 'reprice' : 'keep'];
            app(RecordLifecycleEvent::class)->handle($locked, 'room_moved', $actor, $details);
            Audit::record($actor, 'room_moved', $locked, 'Guest moved room', [...$details, 'room_id' => $room->id]);

            return $locked;
        }, attempts: 3));
    }

    /** @param list<string> $dates */
    private function reprice(Reservation $reservation, RoomType $type, array $dates): void
    {
        $plan = RatePlan::query()->whereHas('roomTypes', fn ($query) => $query->whereKey($type->id))->find($reservation->getAttribute('rate_plan_id'));
        if ($plan === null) {
            throw ValidationException::withMessages(['pricing' => __('The rate plan does not price :type.', ['type' => $type->name])]);
        }
        $quote = app(StayPricing::class)->quote($plan, $type, $dates[0], $reservation->departure->toDateString(), $reservation->adults, $reservation->children);
        if ($quote->unquotable !== null) {
            throw ValidationException::withMessages(['pricing' => $quote->unquotable]);
        }
        foreach ($quote->nights as $night) {
            $reservation->priceLines()->whereDate('date', $night['date'])->update(['amount' => $night['total'], 'currency' => $quote->currency->value]);
        }
    }
}
