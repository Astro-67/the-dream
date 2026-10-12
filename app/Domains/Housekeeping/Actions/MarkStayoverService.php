<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Housekeeping\Models\StayoverService;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Internal entry point for Spec 7; the caller supplies the operating day. */
class MarkStayoverService
{
    public function handle(?User $actor, Room $room, string $businessDate): StayoverService
    {
        return Audit::validationRefusal($actor, $room, 'Stayover service marking refused', fn () => DB::transaction(function () use ($actor, $room, $businessDate) {
            Validator::make(['business_date' => $businessDate], ['business_date' => ['required', 'date_format:Y-m-d']])->validate();
            $status = RoomStatus::query()->where('room_id', $room->id)->lockForUpdate()->sole();
            if ($status->status !== HousekeepingStatus::Occupied) {
                throw ValidationException::withMessages(['status' => __('Only occupied rooms can be marked for stayover service.')]);
            }
            $existing = StayoverService::query()->where('room_id', $room->id)->whereDate('business_date', $businessDate)->first();
            if ($existing) {
                return $existing;
            }
            $service = new StayoverService(['room_id' => $room->id, 'actor_id' => $actor?->id, 'business_date' => $businessDate]);
            $service->forceFill(['organization_id' => $status->organization_id, 'property_id' => $status->property_id])->save();
            Audit::record($actor, 'stayover_service_marked', $service, 'Room marked for stayover service', ['room_id' => $room->id, 'business_date' => $businessDate]);

            return $service;
        }));
    }
}
