<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Domains\Inventory\Models\OutOfServicePeriod;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
            self::ensureFree($room, $details['starts_on'], $details['returns_on']);
            $period = $room->outOfServicePeriods()->create($details);
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'created', $period, 'Room taken out of service', ['attributes' => $period->details()]);

            return $period;
        }));
    }

    /**
     * Refuses dates that overlap another period of the room. Call with the room's room type locked.
     *
     * @throws ValidationException
     */
    public static function ensureFree(Room $room, string $startsOn, string $returnsOn, ?OutOfServicePeriod $except = null): void
    {
        $overlapping = OutOfServicePeriod::query()->where('room_id', $room->id)
            ->when($except, fn ($query, OutOfServicePeriod $period) => $query->whereKeyNot($period->id))
            ->whereDate('starts_on', '<', $returnsOn)->whereDate('returns_on', '>', $startsOn)
            ->orderBy('starts_on')->first();

        if ($overlapping !== null) {
            throw ValidationException::withMessages(['starts_on' => __('Room :number is already out of service from :from until :until.', [
                'number' => $room->number,
                'from' => $overlapping->starts_on->toDateString(),
                'until' => $overlapping->returns_on->toDateString(),
            ])]);
        }
    }
}
