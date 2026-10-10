<?php

namespace App\Domains\Reservations\Actions;

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
            $fields = ['arrival', 'departure', 'adults', 'children', 'room_type_id', 'rate_plan_id', 'room_id', 'restriction_override_reason'];
            $before = $reservation->only($fields);
            $before['price_lines'] = $reservation->priceLines->map->only(['date', 'amount', 'currency', 'rate_plan_id'])->all();
            $type = RoomType::query()->findOrFail($details['room_type_id']);
            $plan = RatePlan::query()->whereNull('deactivated_at')->whereHas('roomTypes', fn ($query) => $query->whereKey($type->id))->findOrFail($details['rate_plan_id']);
            if ($details['adults'] > $type->max_adults || $details['children'] > $type->max_children || $type->max_occupancy < $details['adults'] + $details['children']) {
                throw ValidationException::withMessages(['adults' => __('The guest count exceeds this room type’s capacity.')]);
            }
            $warnings = app(StayRestrictions::class)->warnings($plan, $type, $details['arrival'], $details['departure']);
            $reason = trim($details['restriction_override_reason'] ?? '');
            if ($warnings !== [] && $reason === '') {
                throw ValidationException::withMessages(['restriction_override_reason' => [...$warnings, __('Give a reason to override these restrictions.')]]);
            }
            $dates = DateRange::dates($details['arrival'], CarbonImmutable::parse($details['departure'])->subDay()->toDateString());
            $oldDates = DateRange::dates($reservation->arrival->toDateString(), $reservation->departure->subDay()->toDateString());
            app(RetakeInventory::class)->handle($reservation->getAttribute('room_type_id'), $oldDates, $type->id, $dates);
            $property = Property::query()->findOrFail($reservation->property_id);
            // ponytail: Spec 7 will supply the open business date; use the property's local date until then.
            $today = now($property->timezone)->toDateString();
            $reservation->priceLines()->whereNotIn('date', $dates)->whereDate('date', '>=', $today)->delete();
            foreach ($dates as $date) {
                $line = $reservation->priceLines()->whereDate('date', $date)->first();
                if ($line !== null && ($details['pricing'] === 'keep' || $date < $today)) {
                    continue;
                }
                $quote = app(StayPricing::class)->quote($plan, $type, $date, CarbonImmutable::parse($date)->addDay()->toDateString(), (int) $details['adults'], (int) $details['children']);
                if ($quote->unquotable !== null) {
                    throw ValidationException::withMessages(['rate_plan_id' => $quote->unquotable]);
                }
                $reservation->priceLines()->updateOrCreate(['date' => $date], ['amount' => $quote->nights[0]['total'], 'currency' => $quote->currency->value, 'rate_plan_id' => $plan->id]);
            }
            RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->delete();
            $reservation->update([...array_intersect_key($details, array_flip($fields)), 'room_id' => null, 'restriction_override_reason' => $warnings !== [] ? $reason : null]);
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
