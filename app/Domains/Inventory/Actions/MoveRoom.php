<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Moves a room to another room type of its property, to correct setup mistakes.
 */
class MoveRoom
{
    public function handle(?User $actor, Room $room, RoomType $roomType): Room
    {
        if ($room->room_type_id === $roomType->id) {
            return $room;
        }

        return DB::transaction(function () use ($actor, $room, $roomType) {
            $old = ['room_type_id' => $room->room_type_id];
            $types = RoomType::query()->whereKey([$room->room_type_id, $roomType->id])
                ->orderBy('id')->lockForUpdate()->get();
            $room->roomType()->associate($roomType);
            $room->save();
            $types->each(fn (RoomType $type) => app(RecalculateInventory::class)->handle($type));

            Audit::record($actor, 'moved', $room, 'Room moved to another room type', [
                'old' => $old,
                'attributes' => ['room_type_id' => $roomType->id],
            ]);

            return $room;
        });
    }
}
