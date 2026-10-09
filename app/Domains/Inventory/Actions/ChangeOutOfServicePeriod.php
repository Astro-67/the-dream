<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Changes an out-of-service period's dates or reason, including ending it early.
 */
class ChangeOutOfServicePeriod
{
    /**
     * @param  array{starts_on: string, returns_on: string, reason: string}  $details
     *
     * @throws InventoryRefused when it would leave fewer sellable rooms than are sold
     */
    public function handle(?User $actor, OutOfServicePeriod $period, array $details): OutOfServicePeriod
    {
        return InventoryRefused::audited($actor, $period, 'Out-of-service change refused', fn () => DB::transaction(function () use ($actor, $period, $details) {
            $type = RoomType::query()->whereKey($period->room->room_type_id)->lockForUpdate()->firstOrFail();
            $old = $period->details();
            $period->fill(Arr::only($details, ['starts_on', 'returns_on', 'reason']));

            if (! $period->isDirty()) {
                return $period;
            }

            TakeRoomOutOfService::ensureFree($period->room, $details['starts_on'], $details['returns_on'], $period);
            $period->save();
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'updated', $period, 'Out-of-service period changed', ['old' => $old, 'attributes' => $period->details()]);

            return $period;
        }));
    }
}
