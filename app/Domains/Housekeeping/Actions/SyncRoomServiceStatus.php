<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Reconciles housekeeping with Inventory's half-open service periods. */
class SyncRoomServiceStatus
{
    public function handle(?User $actor, Room $room): RoomStatus
    {
        return DB::transaction(function () use ($actor, $room) {
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $property = Property::query()->findOrFail($room->property_id);
            $date = now($property->timezone)->toDateString();
            $period = $room->outOfServicePeriods()->whereDate('starts_on', '<=', $date)->whereDate('returns_on', '>', $date)->first();
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            if ($period !== null && $status->status !== HousekeepingStatus::Unavailable) {
                return app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::Unavailable, $period->reason, serviceChanged: true);
            }
            if ($period === null && $status->status === HousekeepingStatus::Unavailable) {
                return app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::Dirty, __('Room returned to service.'), serviceChanged: true);
            }

            return $status;
        });
    }
}
