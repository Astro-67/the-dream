<?php

namespace App\Domains\Housekeeping;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Actions\SyncRoomServiceStatus;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Models\Room;

class RoomReadiness
{
    public function isReady(Room $room): bool
    {
        if (app(SyncRoomServiceStatus::class)->activePeriod($room) !== null) {
            return false;
        }

        $status = RoomStatus::query()->where('room_id', $room->id)->value('status');

        return $status !== HousekeepingStatus::Unavailable && ($status === HousekeepingStatus::Clean || $this->overrideFor($room) !== null);
    }

    public function overrideFor(Room $room): ?StatusChange
    {
        return StatusChange::query()->where('room_id', $room->id)->where('kind', 'ready_override')
            ->whereDate('business_date', BusinessDate::of($room))->latest('changed_at')->latest('id')->first();
    }
}
