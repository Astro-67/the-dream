<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class RetakeInventory
{
    /**
     * @param  list<string>  $oldDates
     * @param  list<string>  $newDates
     */
    public function handle(string $oldType, array $oldDates, string $newType, array $newDates): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Amending inventory requires a transaction.');
        }
        // Match booking and inventory recalculation's type lock; order both types before dates.
        $types = array_values(array_unique([$oldType, $newType]));
        sort($types);
        foreach ($types as $type) {
            RoomType::query()->whereKey($type)->lockForUpdate()->firstOrFail();
        }
        $allowance = RoomType::query()->findOrFail($newType)->overbookingAllowance();
        $records = InventoryRecord::query()->where(function ($query) use ($oldType, $oldDates, $newType, $newDates) {
            $query->where(fn ($query) => $query->where('room_type_id', $oldType)->whereIn('date', $oldDates))
                ->orWhere(fn ($query) => $query->where('room_type_id', $newType)->whereIn('date', $newDates));
        })->orderBy('date')->orderBy('room_type_id')->lockForUpdate()->get();
        $expected = count(array_unique([...array_map(fn ($date) => $oldType.$date, $oldDates), ...array_map(fn ($date) => $newType.$date, $newDates)]));
        if ($records->count() !== $expected) {
            throw ValidationException::withMessages(['room_type_id' => __('Inventory is missing for one or more nights.')]);
        }
        foreach ($records as $record) {
            $date = $record->date->toDateString();
            $release = $record->room_type_id === $oldType && in_array($date, $oldDates, true);
            $take = $record->room_type_id === $newType && in_array($date, $newDates, true);
            $sold = $record->sold_count - (int) $release + (int) $take;
            if (($release && $record->sold_count < 1) || $sold < 0 || ($take && $sold > $record->sellable_count + $allowance)) {
                throw ValidationException::withMessages(['room_type_id' => __('No rooms are left for every night of this stay.')]);
            }
            $record->sold_count = $sold;
            $record->save();
        }
    }
}
