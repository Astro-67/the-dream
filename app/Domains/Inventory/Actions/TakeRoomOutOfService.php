<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Takes a room out of service for a date range with a reason.
 */
class TakeRoomOutOfService
{
    /**
     * @param  array{starts_on: string, returns_on: string, reason: string}  $details
     *
     * @throws InventoryRefused when it would leave fewer sellable rooms than are sold
     */
    public function handle(?User $actor, Room $room, array $details): OutOfServicePeriod
    {
        return InventoryRefused::audited($actor, $room, 'Room out of service refused', fn () => DB::transaction(function () use ($actor, $room, $details) {
            $type = RoomType::query()->whereKey($room->room_type_id)->lockForUpdate()->firstOrFail();
            OutOfServicePeriod::ensureFree($room, $details['starts_on'], $details['returns_on']);
            $period = $room->outOfServicePeriods()->create($details);
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'created', $period, 'Room taken out of service', ['attributes' => $period->details()]);

            return $period;
        }));
    }
}
