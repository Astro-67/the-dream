<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StatusChange;
use App\Domains\Housekeeping\StatusTransitions;
use App\Domains\Inventory\Models\Room;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ChangeRoomStatus
{
    public function handle(?User $actor, Room $room, HousekeepingStatus $to, string $reason, bool $serviceChanged = false): RoomStatus
    {
        return Audit::validationRefusal($actor, $room, 'Housekeeping status change refused', fn () => DB::transaction(function () use ($actor, $room, $to, $reason, $serviceChanged) {
            $room = Room::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            $from = $status->status;
            if (! $serviceChanged && app(SyncRoomServiceStatus::class)->activePeriod($room) !== null) {
                throw ValidationException::withMessages(['status' => __('The room is out of service. Change its service period before changing housekeeping status.')]);
            }
            if (! $serviceChanged && $from === HousekeepingStatus::InspectionRequired) {
                Gate::forUser($actor)->authorize('supervise', RoomStatus::class);
                if ($to === HousekeepingStatus::Dirty && trim($reason) === '') {
                    throw ValidationException::withMessages(['reason' => __('A reason is required for a failed inspection.')]);
                }
            }
            app(StatusTransitions::class)->ensureAllowed($from, $to, Property::query()->findOrFail($status->property_id)->housekeeping_inspection_required, serviceChanged: $serviceChanged);
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
