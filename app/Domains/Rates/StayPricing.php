<?php

namespace App\Domains\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Data\StayQuote;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Nightly prices of a stay on one rate plan and room type: the pricing query
 * the quote preview, reservations (Spec 4) and folios (Spec 6) use.
 */
class StayPricing
{
    public function quote(RatePlan $plan, RoomType $roomType, string $arrival, string $departure, int $adults, int $children): StayQuote
    {
        $reason = match (true) {
            $departure <= $arrival => __('Departure must be after arrival.'),
            $adults < 1 => __('A stay needs at least one adult.'),
            $adults > $roomType->max_adults || $children > $roomType->max_children || $adults + $children > $roomType->max_occupancy => __(':room takes at most :count guests.', ['room' => $roomType->name, 'count' => $roomType->max_occupancy]),
            default => null,
        };
        if ($reason !== null) {
            return new StayQuote($plan->currency, [], $reason);
        }

        $dates = DateRange::dates($arrival, CarbonImmutable::parse($departure)->subDay()->format('Y-m-d'));
        $prices = $this->storedPrices($plan, $roomType, $dates);
        // Children fill free base places before adults pay extra; neither is charged twice.
        $extraAdults = max(0, $adults - $roomType->base_occupancy);
        $extraChildren = max(0, $adults + $children - $roomType->base_occupancy) - $extraAdults;
        $nights = [];

        foreach ($dates as $date) {
            $price = $prices->get($date);
            if ($price === null) {
                return new StayQuote($plan->currency, [], __('No price is set for :date.', ['date' => $date]));
            }
            $night = ['date' => $date, 'base_price' => $price->base_price, 'extra_adults' => $extraAdults * $price->extra_adult, 'extra_children' => $extraChildren * $price->extra_child];
            $nights[] = [...$night, 'total' => $night['base_price'] + $night['extra_adults'] + $night['extra_children']];
        }

        return new StayQuote($plan->currency, $nights);
    }

    /**
     * The plan's prices, keyed by date: stored ones, or for a derived plan its base plan's, adjusted.
     *
     * @param  list<string>  $dates
     * @return Collection<string, RatePrice>
     */
    private function storedPrices(RatePlan $plan, RoomType $roomType, array $dates): Collection
    {
        return RatePrice::query()
            ->where('rate_plan_id', $plan->base_rate_plan_id ?? $plan->id)
            ->where('room_type_id', $roomType->id)
            ->whereBetween('date', [$dates[0], end($dates)])
            ->get()
            ->keyBy(fn (RatePrice $price) => $price->date->format('Y-m-d'))
            ->map($plan->priced(...));
    }
}
