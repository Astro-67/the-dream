<?php

namespace App\Domains\Housekeeping;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Tenancy\Models\Property;

class RoomReadiness
{
    public function isReady(Room $room): bool
    {
        return RoomStatus::query()->where('room_id', $room->id)->value('status') === HousekeepingStatus::Clean || $this->overrideFor($room) !== null;
    }

    public function businessDate(Room $room): string
    {
        // ponytail: local date until Spec 7 supplies the open business-date clock.
        return now(Property::query()->findOrFail($room->property_id)->timezone)->toDateString();
    }

    public function overrideFor(Room $room): ?StatusChange
    {
        return StatusChange::query()->where('room_id', $room->id)->where('kind', 'ready_override')
            ->whereDate('business_date', $this->businessDate($room))->latest('changed_at')->latest('id')->first();
    }
}
