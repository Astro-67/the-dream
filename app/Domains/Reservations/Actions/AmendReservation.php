<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Actions\RetakeInventory;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\DateRange;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Rates\StayRestrictions;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AmendReservation
{
    /** @param array<string, mixed> $details */
    public function handle(?User $actor, Reservation $reservation, array $details): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Reservation amendment refused', fn () => DB::transaction(function () use ($actor, $reservation, $details) {
            $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($reservation->status !== ReservationStatus::Confirmed) {
                throw ValidationException::withMessages(['reservation' => __('Only confirmed reservations can be amended.')]);
            }
            $property = Property::query()->findOrFail($reservation->property_id);
            $today = BusinessDate::of($property);
            if ($details['arrival'] < $today && $details['arrival'] !== $reservation->arrival->toDateString()) {
                throw ValidationException::withMessages(['arrival' => __('A changed arrival cannot be in the past at this property.')]);
            }
            $fields = ['arrival', 'departure', 'adults', 'children', 'room_type_id', 'rate_plan_id', 'room_id', 'restriction_override_reason'];
            $before = $reservation->only($fields);
            $before['price_lines'] = $reservation->priceLines->map->only(['date', 'amount', 'currency', 'rate_plan_id'])->all();
            $type = RoomType::query()->findOrFail($details['room_type_id']);
            $plan = RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($type->id))->findOrFail($details['rate_plan_id']);
            $invalid = app(StayPricing::class)->invalidStay($type, $details['arrival'], $details['departure'], (int) $details['adults'], (int) $details['children']);
            if ($invalid !== null) {
                throw ValidationException::withMessages(['adults' => $invalid]);
            }
            $warnings = app(StayRestrictions::class)->warnings($plan, $type, $details['arrival'], $details['departure']);
            $reason = app(StayRestrictions::class)->overrideReason($warnings, $details['restriction_override_reason'] ?? null);
            $dates = DateRange::dates($details['arrival'], CarbonImmutable::parse($details['departure'])->subDay()->toDateString());
            $oldDates = DateRange::dates($reservation->arrival->toDateString(), $reservation->departure->subDay()->toDateString());
            app(RetakeInventory::class)->handle($reservation->getAttribute('room_type_id'), $oldDates, $type->id, $dates);
            $reservation->priceLines()->where(fn ($query) => $query->whereDate('date', '<', $details['arrival'])->orWhereDate('date', '>=', $details['departure']))->whereDate('date', '>=', $today)->delete();
            foreach ($dates as $date) {
                $line = $reservation->priceLines()->whereDate('date', $date)->first();
                if ($date < $today || ($line !== null && $details['pricing'] === 'keep')) {
                    continue;
                }
                $quote = app(StayPricing::class)->quote($plan, $type, $date, CarbonImmutable::parse($date)->addDay()->toDateString(), (int) $details['adults'], (int) $details['children']);
                if ($quote->unquotable !== null) {
                    throw ValidationException::withMessages(['rate_plan_id' => $quote->unquotable]);
                }
                $values = ['amount' => $quote->nights[0]['total'], 'currency' => $quote->currency->value, 'rate_plan_id' => $plan->id];
                if ($line !== null) {
                    $line->update($values);
                } else {
                    $reservation->priceLines()->create(['date' => $date, ...$values]);
                }
            }
            RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->delete();
            $reservation->update([...array_intersect_key($details, array_flip($fields)), 'room_id' => null, 'restriction_override_reason' => $reason]);
            if (($details['room_id'] ?? null) !== null) {
                $reservation = app(AssignRoom::class)->handle($actor, $reservation, $details['room_id'], recordEvent: false);
            }
            $reservation->unsetRelation('priceLines');
            $after = $reservation->only($fields);
            $after['price_lines'] = $reservation->priceLines->map->only(['date', 'amount', 'currency', 'rate_plan_id'])->all();
            app(RecordLifecycleEvent::class)->handle($reservation, 'modified', $actor, ['before' => $before, 'after' => $after, 'pricing' => $details['pricing']]);
            Audit::record($actor, 'updated', $reservation, 'Reservation amended', ['old' => $before, 'attributes' => $after, 'pricing' => $details['pricing'], 'restriction_warnings' => $warnings]);

            return $reservation;
        }, attempts: 3));
    }
}
