<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Housekeeping\Actions\InitializeRoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a room to a room type of the property in the current tenant context.
 */
class CreateRoom
{
    /**
     * @param  array<string, mixed>  $details  validated number, floor and room_type_id
     */
    public function handle(?User $actor, array $details): Room
    {
        return DB::transaction(function () use ($actor, $details) {
            $type = RoomType::query()->whereKey($details['room_type_id'])->lockForUpdate()->firstOrFail();
            $room = Room::query()->create($details);
            app(InitializeRoomStatus::class)->handle($room);
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'created', $room, 'Room created', [
                'attributes' => $room->only(['number', 'floor', 'room_type_id']),
            ]);

            return $room;
        });
    }
}
