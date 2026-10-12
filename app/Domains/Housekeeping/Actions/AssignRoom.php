<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class AssignRoom
{
    public function handle(User $actor, Room $room, string $housekeeperId, string $businessDate): Assignment
    {
        return DB::transaction(function () use ($actor, $room, $housekeeperId, $businessDate) {
            Room::query()->whereKey($room->id)->lockForUpdate()->sole();
            $assignment = Assignment::query()->firstOrCreate([
                'room_id' => $room->id, 'housekeeper_id' => $housekeeperId, 'business_date' => $businessDate,
            ]);
            if ($assignment->wasRecentlyCreated) {
                Audit::record($actor, 'assigned', $assignment, 'Housekeeping room assigned');
            }

            return $assignment;
        });
    }
}
