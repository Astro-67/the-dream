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
use Illuminate\Validation\ValidationException;

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

    /**
     * Refuses dates that overlap another period of the room. Call with the room's room type locked.
     *
     * @throws ValidationException
     */
    public static function ensureFree(Room $room, string $startsOn, string $returnsOn, ?self $except = null): void
    {
        $overlapping = self::query()->where('room_id', $room->id)
            ->when($except, fn ($query, self $period) => $query->whereKeyNot($period->id))
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
