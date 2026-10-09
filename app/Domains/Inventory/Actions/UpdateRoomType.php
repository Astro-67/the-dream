<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Changes a room type's details and records the changed fields, before and after.
 */
class UpdateRoomType
{
    /**
     * @param  array<string, mixed>  $details  validated room type fields (RoomType::FIELDS)
     */
    public function handle(?User $actor, RoomType $roomType, array $details): RoomType
    {
        return DB::transaction(function () use ($actor, $roomType, $details) {
            $roomType->fill($details);
            $changed = array_keys($roomType->getDirty());

            if ($changed === []) {
                return $roomType;
            }

            $old = array_map(fn (string $key) => $roomType->getOriginal($key), array_combine($changed, $changed));
            $roomType->save();

            Audit::record($actor, 'updated', $roomType, 'Room type updated', [
                'old' => $old,
                'attributes' => $roomType->only($changed),
            ]);

            return $roomType;
        });
    }
}
