<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Enums\TransitionSource;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Reconciles housekeeping with Inventory's half-open service periods. */
class SyncRoomServiceStatus
{
    public function activePeriod(Room $room): ?OutOfServicePeriod
    {
        $date = BusinessDate::of($room);

        return $room->outOfServicePeriods()->whereDate('starts_on', '<=', $date)->whereDate('returns_on', '>', $date)->first();
    }

    public function handle(?User $actor, Room $room): RoomStatus
    {
        return DB::transaction(function () use ($actor, $room) {
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $period = $this->activePeriod($room);
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            if ($period !== null && $status->status !== HousekeepingStatus::Unavailable) {
                return app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::Unavailable, $period->reason, source: TransitionSource::ServicePeriod);
            }
            if ($period === null && $status->status === HousekeepingStatus::Unavailable) {
                return app(ChangeRoomStatus::class)->handle($actor, $room, HousekeepingStatus::Dirty, __('Room returned to service.'), source: TransitionSource::ServicePeriod);
            }

            return $status;
        });
    }
}
