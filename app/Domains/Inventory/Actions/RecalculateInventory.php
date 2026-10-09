<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Inventory\RoomAvailability;
use App\Domains\Tenancy\Models\Property;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns sellable counts and fills missing horizon dates. Never writes sold counts.
 * Room changes call this inside their transaction; the daily extension uses it too.
 */
class RecalculateInventory
{
    public function __construct(private RoomAvailability $availability) {}

    /**
     * @throws InventoryRefused when a date would lose sellable rooms below its sold count
     */
    public function handle(RoomType $roomType): void
    {
        DB::transaction(function () use ($roomType): void {
            $type = RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
            $property = Property::query()->findOrFail($type->property_id);
            $today = CarbonImmutable::now($property->timezone)->startOfDay();
            $end = $today->addYearsNoOverflow((int) config('inventory.horizon_years'));
            $sellable = $this->availability->sellableByDate($type, $today, $end);
            $stranded = $this->strandedDates($type, $sellable);

            if ($stranded !== []) {
                throw InventoryRefused::belowSold($type, $stranded);
            }

            $rows = [];
            foreach ($sellable as $date => $count) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $type->organization_id,
                    'property_id' => $type->property_id,
                    'room_type_id' => $type->id,
                    'date' => $date,
                    'sellable_count' => $count,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                InventoryRecord::query()->upsert($chunk, ['property_id', 'room_type_id', 'date'], ['sellable_count', 'updated_at']);
            }
        });
    }

    /**
     * Dates this change lowers below what is already sold. Dates already below
     * sold before the change (an overbooking) are not this change's doing.
     *
     * @param  array<string, int>  $sellable
     * @return list<string>
     */
    private function strandedDates(RoomType $type, array $sellable): array
    {
        $stranded = [];
        $sold = InventoryRecord::query()->where('room_type_id', $type->id)->where('sold_count', '>', 0)
            ->whereDate('date', '>=', (string) array_key_first($sellable))->whereDate('date', '<=', (string) array_key_last($sellable))
            ->orderBy('date')->get();

        foreach ($sold as $record) {
            $date = $record->date->toDateString();
            if ($sellable[$date] < $record->sold_count && $sellable[$date] < $record->sellable_count) {
                $stranded[] = $date;
            }
        }

        return $stranded;
    }
}
