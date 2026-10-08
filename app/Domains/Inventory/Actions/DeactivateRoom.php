<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\Room;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Takes a room out of sale for good. The room is kept, so its history stays.
 */
class DeactivateRoom
{
    public function handle(?User $actor, Room $room): Room
    {
        if ($room->deactivated_at !== null) {
            return $room;
        }

        return InventoryRefused::audited($actor, $room, 'Room deactivation refused', fn () => DB::transaction(function () use ($actor, $room) {
            $type = $room->roomType()->lockForUpdate()->firstOrFail();
            $room->deactivated_at = now();
            $room->save();
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'deactivated', $room, 'Room deactivated', [
                'old' => ['deactivated_at' => null],
                'attributes' => ['deactivated_at' => $room->deactivated_at->toIso8601String()],
            ]);

            return $room;
        }));
    }
}
