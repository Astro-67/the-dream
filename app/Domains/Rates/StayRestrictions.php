<?php

namespace App\Domains\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use Carbon\CarbonImmutable;

class StayRestrictions
{
    /**
     * Arrival rules apply on arrival; stop-sell applies on every occupied night.
     * Staff may override these warnings; channel callers must treat them as binding.
     *
     * @return list<string>
     */
    public function warnings(RatePlan $plan, RoomType $roomType, string $arrival, string $departure): array
    {
        $nights = (int) CarbonImmutable::parse($arrival)->diffInDays(CarbonImmutable::parse($departure));
        $restrictions = RateRestriction::query()->where('rate_plan_id', $plan->id)->where('room_type_id', $roomType->id)->where('date', '>=', $arrival)->where('date', '<', $departure)->orderBy('date')->get();
        $warnings = [];
        foreach ($restrictions as $restriction) {
            $date = $restriction->date->toDateString();
            if ($date === $arrival && $nights < $restriction->min_stay) {
                $warnings[] = __('Minimum stay on :date is :minimum nights; this stay is :nights nights.', ['date' => $date, 'minimum' => $restriction->min_stay, 'nights' => $nights]);
            }
            if ($date === $arrival && $restriction->closed_to_arrival) {
                $warnings[] = __('Closed to arrival on :date.', ['date' => $date]);
            }
            if ($restriction->stop_sell) {
                $warnings[] = __('Stop-sell on :date.', ['date' => $date]);
            }
        }

        return $warnings;
    }
}
