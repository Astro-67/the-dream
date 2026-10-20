<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Takes inventory inside the caller's reservation transaction. */
class TakeInventory
{
    /** @param list<string> $dates */
    public function handle(RoomType $roomType, array $dates, int $rooms = 1): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Taking inventory requires a transaction.');
        }
        // RecalculateInventory uses the same type lock before changing sellable counts.
        RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
        $allowance = $roomType->overbookingAllowance();
        $records = InventoryRecord::query()->where('room_type_id', $roomType->id)
            ->whereIn('date', $dates)->orderBy('date')->lockForUpdate()->get();
        if ($records->count() !== count($dates) || $records->contains(fn (InventoryRecord $record) => $record->sold_count + $rooms > $record->sellable_count + $allowance)) {
            throw ValidationException::withMessages(['room_type_id' => __('No rooms are left for every night of this stay.')]);
        }
        foreach ($records as $record) {
            $record->increment('sold_count', $rooms);
        }
    }
}
