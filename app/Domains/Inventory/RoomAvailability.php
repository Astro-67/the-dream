<?php

namespace App\Domains\Inventory;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\RoomType;
use Carbon\CarbonImmutable;

/**
 * Rooms of a room type by date, derived from active rooms and out-of-service
 * periods. Sellable on a date is the active rooms minus those out of service.
 */
class RoomAvailability
{
    /**
     * @return array<string, int> active rooms out of service, by date from $from through $to
     */
    public function outOfServiceByDate(RoomType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = [];
        for ($date = $from; $date <= $to; $date = $date->addDay()) {
            $counts[$date->toDateString()] = 0;
        }

        $periods = OutOfServicePeriod::query()
            ->whereIn('room_id', $type->rooms()->active()->select('id'))
            ->whereDate('returns_on', '>', $from->toDateString())
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->get();

        foreach ($periods as $period) {
            for ($date = $period->starts_on->max($from); $date < $period->returns_on && $date <= $to; $date = $date->addDay()) {
                $counts[$date->toDateString()]++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, int> sellable rooms, by date from $from through $to
     */
    public function sellableByDate(RoomType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $active = $type->rooms()->active()->count();

        return array_map(fn (int $out) => $active - $out, $this->outOfServiceByDate($type, $from, $to));
    }

    /** @return array<string, int> available rooms, by date from $from through $to */
    public function availableByDate(RoomType $type, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $available = $this->sellableByDate($type, $from, $to);
        $sold = InventoryRecord::query()->where('room_type_id', $type->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])->get();

        foreach ($sold as $record) {
            $available[$record->date->toDateString()] -= $record->sold_count;
        }

        return $available;
    }
}
