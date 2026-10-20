<?php

namespace App\Domains\Reservations;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\DateRange;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\StayPricing;
use App\Domains\Reservations\Policies\AvailabilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

#[UsePolicy(AvailabilityPolicy::class)]
class AvailabilitySearch
{
    public function __construct(private StayPricing $pricing) {}

    /**
     * @param  array{arrival: string, departure: string, adults: int|string, children: int|string, room_type_id?: string|null}  $stay
     * @return list<array<string, mixed>>
     */
    public function search(array $stay): array
    {
        $dates = DateRange::dates($stay['arrival'], CarbonImmutable::parse($stay['departure'])->subDay()->toDateString());
        $types = RoomType::query()->when($stay['room_type_id'] ?? null, fn ($query, $id) => $query->whereKey($id))->orderBy('code')->get();
        $inventory = InventoryRecord::query()->whereIn('room_type_id', $types->pluck('id'))->whereBetween('date', [$dates[0], end($dates)])->get()->groupBy('room_type_id');
        $plans = RatePlan::query()->whereNull('deactivated_at')->with('roomTypes:id')->orderBy('code')->get();
        $results = [];
        foreach ($types as $type) {
            $records = $inventory->get($type->id);
            if ($records === null || $records->count() !== count($dates)) {
                continue;
            }
            $allowance = $type->overbookingAllowance();
            $available = $records->min(fn (InventoryRecord $record) => $record->sellable_count + $allowance - $record->sold_count);
            if ($available < 1) {
                continue;
            }
            $quotes = [];
            foreach ($plans as $plan) {
                if (! $plan->roomTypes->contains('id', $type->id)) {
                    continue;
                }
                $quote = $this->pricing->quote($plan, $type, $stay['arrival'], $stay['departure'], (int) $stay['adults'], (int) $stay['children']);
                if ($quote->unquotable === null) {
                    $quotes[] = ['id' => $plan->id, 'name' => $plan->name, 'currency' => $quote->currency->value, 'nights' => $quote->nights, 'total' => $quote->total()];
                }
            }
            if ($quotes !== []) {
                $results[] = [...$type->only(['id', 'name', 'code']), 'available' => $available, 'plans' => $quotes];
            }
        }

        return $results;
    }
}
