<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
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
    public function handle(RoomType $roomType): void
    {
        DB::transaction(function () use ($roomType): void {
            $type = RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
            $property = Property::query()->findOrFail($type->property_id);
            $today = CarbonImmutable::now($property->timezone)->startOfDay();
            $end = $today->addYearsNoOverflow((int) config('inventory.horizon_years'));
            $sellable = $type->rooms()->active()->count();
            $rows = [];

            for ($date = $today; $date <= $end; $date = $date->addDay()) {
                $rows[] = [
                    'id' => (string) Str::ulid(),
                    'organization_id' => $type->organization_id,
                    'property_id' => $type->property_id,
                    'room_type_id' => $type->id,
                    'date' => $date->toDateString(),
                    'sellable_count' => $sellable,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                InventoryRecord::query()->upsert($chunk, ['property_id', 'room_type_id', 'date'], ['sellable_count', 'updated_at']);
            }

            InventoryRecord::query()->where('property_id', $type->property_id)
                ->where('room_type_id', $type->id)->update(['sellable_count' => $sellable]);
        });
    }
}
