<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\RoomType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

class ReleaseInventory
{
    /** @param list<string> $dates */
    public function handle(RoomType $roomType, array $dates): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Releasing inventory requires a transaction.');
        }
        RoomType::query()->whereKey($roomType->id)->lockForUpdate()->firstOrFail();
        $records = InventoryRecord::query()->where('room_type_id', $roomType->id)
            ->whereIn('date', $dates)->orderBy('date')->lockForUpdate()->get();
        if ($records->count() !== count($dates) || $records->contains(fn (InventoryRecord $record) => $record->sold_count < 1)) {
            throw ValidationException::withMessages(['reservation' => __('Sold inventory is missing for this reservation.')]);
        }
        foreach ($records as $record) {
            $record->decrement('sold_count');
        }
    }
}
