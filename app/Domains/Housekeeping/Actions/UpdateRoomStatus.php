<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Inventory\Models\Room;
use App\Models\User;

class UpdateRoomStatus
{
    public function handle(User $actor, Room $room, string $status, string $reason): void
    {
        if ($status === 'cleaned') {
            app(MarkRoomCleaned::class)->handle($actor, $room);
        } else {
            app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::from($status), $reason);
        }
    }
}
