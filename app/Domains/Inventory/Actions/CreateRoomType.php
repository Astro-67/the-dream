<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Inventory\Models\RoomType;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Adds a room type to the property in the current tenant context.
 */
class CreateRoomType
{
    /**
     * @param  array<string, mixed>  $details  validated room type fields (RoomType::FIELDS)
     */
    public function handle(?User $actor, array $details): RoomType
    {
        return DB::transaction(function () use ($actor, $details) {
            $roomType = RoomType::query()->create($details);
            app(RecalculateInventory::class)->handle($roomType);

            Audit::record($actor, 'created', $roomType, 'Room type created', [
                'attributes' => $roomType->only(RoomType::FIELDS),
            ]);

            return $roomType;
        });
    }
}
