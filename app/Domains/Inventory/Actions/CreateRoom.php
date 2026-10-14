<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Housekeeping\Actions\InitializeRoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Subscriptions\Entitlements;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Adds a room to a room type of the property in the current tenant context.
 */
class CreateRoom
{
    /**
     * @param  array<string, mixed>  $details  validated number, floor and room_type_id
     */
    public function handle(?User $actor, array $details): Room
    {
        return Audit::validationRefusal($actor, new Room, 'Room creation refused', fn () => DB::transaction(function () use ($actor, $details) {
            $entitlements = app(Entitlements::class);
            $property = Tenancy::property() ?? throw MissingTenantContext::forProperty(Room::class);
            $entitlements->assertCapacity($entitlements->lockForNewSales($property->demo), rooms: 1);
            $type = RoomType::query()->whereKey($details['room_type_id'])->lockForUpdate()->firstOrFail();
            $room = Room::query()->create($details);
            app(InitializeRoomStatus::class)->handle($room);
            app(RecalculateInventory::class)->handle($type);

            Audit::record($actor, 'created', $room, 'Room created', [
                'attributes' => $room->only(['number', 'floor', 'room_type_id']),
            ]);

            return $room;
        }, attempts: 3));
    }
}
