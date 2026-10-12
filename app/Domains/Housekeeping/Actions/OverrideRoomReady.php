<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Housekeeping\RoomReadiness;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class OverrideRoomReady
{
    public function handle(User $actor, Room $room, string $reason): StatusChange
    {
        return DB::transaction(function () use ($actor, $room, $reason) {
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            $date = app(RoomReadiness::class)->businessDate($room);
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
        });
    }
}
