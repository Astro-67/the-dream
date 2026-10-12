<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;

class InitializeRoomStatus
{
    public function handle(Room $room): RoomStatus
    {
        $status = new RoomStatus(['room_id' => $room->id, 'status' => HousekeepingStatus::Dirty]);
        $status->forceFill(['organization_id' => $room->organization_id, 'property_id' => $room->property_id])->save();

        return $status;
    }
}
