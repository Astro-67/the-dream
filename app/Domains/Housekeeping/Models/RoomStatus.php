<?php

namespace App\Domains\Housekeeping\Models;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Inventory\Models\Room;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_id
 * @property HousekeepingStatus $status
 * @property-read Room $room
 */
#[Fillable(['room_id', 'status'])]
class RoomStatus extends Model
{
    use BelongsToProperty, HasUlids;

    protected $table = 'housekeeping_room_statuses';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => HousekeepingStatus::class];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
