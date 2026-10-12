<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;

class RoomReadiness
{
    public function isReady(Room $room): bool
    {
        return RoomStatus::query()->where('room_id', $room->id)->value('status') === HousekeepingStatus::Clean;
    }
}
