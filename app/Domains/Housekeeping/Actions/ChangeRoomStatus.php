<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\StatusTransitions;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class ChangeRoomStatus
{
    public function handle(?User $actor, Room $room, HousekeepingStatus $to, string $reason): RoomStatus
    {
        return Audit::validationRefusal($actor, $room, 'Housekeeping status change refused', fn () => DB::transaction(function () use ($actor, $room, $to, $reason) {
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            $from = $status->status;
            app(StatusTransitions::class)->ensureAllowed($from, $to);
            $status->update(['status' => $to]);
            $change = new StatusChange([
                'room_id' => $room->id, 'actor_id' => $actor?->id,
                'from_status' => $from, 'to_status' => $to,
                'reason' => $reason, 'changed_at' => now(),
            ]);
            $change->forceFill(['organization_id' => $status->organization_id, 'property_id' => $status->property_id])->save();
            Audit::record($actor, 'status_changed', $status, 'Housekeeping status changed', [
                'from' => $from->value, 'to' => $to->value, 'reason' => $reason,
            ]);

            return $status;
        }));
    }
}
