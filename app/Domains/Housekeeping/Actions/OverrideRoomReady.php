<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OverrideRoomReady
{
    public function handle(User $actor, Room $room, string $reason): StatusChange
    {
        return Audit::validationRefusal($actor, $room, 'Room readiness override refused', fn () => DB::transaction(function () use ($actor, $room, $reason) {
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->sole();
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            if ($status->status === HousekeepingStatus::Unavailable || app(SyncRoomServiceStatus::class)->activePeriod($room) !== null) {
                throw ValidationException::withMessages(['reason' => __('An out-of-service room cannot be made ready. Return it to service first.')]);
            }
            $date = BusinessDate::forPropertyId($room->property_id);
            $override = new StatusChange([
                'room_id' => $room->id, 'actor_id' => $actor->id,
                'from_status' => $status->status, 'to_status' => $status->status,
                'reason' => $reason, 'changed_at' => now(),
                'kind' => 'ready_override', 'business_date' => $date,
            ]);
            $override->forceFill(['organization_id' => $status->organization_id, 'property_id' => $status->property_id])->save();
            Audit::record($actor, 'ready_overridden', $override, 'Room readiness overridden', [
                'room_id' => $room->id, 'business_date' => $date, 'reason' => $reason,
            ]);

            return $override;
        }));
    }
}
