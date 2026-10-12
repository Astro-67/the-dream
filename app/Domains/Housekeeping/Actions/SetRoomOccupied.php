<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Models\User;

/** Internal entry point for Spec 6; readiness is enforced by check-in. */
class SetRoomOccupied
{
    public function handle(?User $actor, Room $room, string $reason): RoomStatus
    {
        return app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::Occupied, $reason, occupancyLifecycle: true);
    }
}
