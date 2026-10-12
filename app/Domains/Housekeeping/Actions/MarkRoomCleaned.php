<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;

class MarkRoomCleaned
{
    public function handle(User $actor, Room $room): void
    {
        $property = Property::query()->findOrFail($room->property_id);
        app(ChangeRoomStatus::class)->handle($actor, $room, $property->housekeeping_inspection_required
            ? HousekeepingStatus::InspectionRequired : HousekeepingStatus::Clean, __('Cleaning completed'));
    }
}
