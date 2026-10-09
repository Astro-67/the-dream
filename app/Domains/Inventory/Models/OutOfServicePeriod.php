<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Policies\InventoryPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dates a room is not sold, with the reason. Covers starts_on up to the day
 * before returns_on. Periods of one room never overlap.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $returns_on
 * @property string $reason
 * @property-read Room $room
 */
#[Fillable(['room_id', 'starts_on', 'returns_on', 'reason'])]
#[UsePolicy(InventoryPolicy::class)]
class OutOfServicePeriod extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'returns_on' => 'immutable_date'];
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** @return array{room_id: string, starts_on: string, returns_on: string, reason: string} */
    public function details(): array
    {
        return [
            'room_id' => $this->room_id,
            'starts_on' => $this->starts_on->toDateString(),
            'returns_on' => $this->returns_on->toDateString(),
            'reason' => $this->reason,
        ];
    }
}
