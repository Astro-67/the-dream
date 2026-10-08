<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Changes a room's number and floor. Its room type changes only through MoveRoom.
 */
class UpdateRoom
{
    /**
     * @param  array<string, mixed>  $details  validated number and floor
     */
    public function handle(?User $actor, Room $room, array $details): Room
    {
        return DB::transaction(function () use ($actor, $room, $details) {
            $room->fill(Arr::only($details, ['number', 'floor']));
            $changed = array_keys($room->getDirty());

            if ($changed === []) {
                return $room;
            }

            $old = array_map(fn (string $key) => $room->getOriginal($key), array_combine($changed, $changed));
            $room->save();

            Audit::record($actor, 'updated', $room, 'Room updated', [
                'old' => $old,
                'attributes' => $room->only($changed),
            ]);

            return $room;
        });
    }
}
