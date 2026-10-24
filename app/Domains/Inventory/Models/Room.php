<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Policies\InventoryPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * A physical room of a property. Deactivated rooms are no longer sold but are
 * kept, so their history stays.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_type_id
 * @property string $number
 * @property string|null $floor
 * @property int $vfms_room_id the room's ID for VFMS: the next whole number in the property, never edited or reused
 * @property CarbonImmutable|null $deactivated_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read RoomType $roomType
 */
#[Fillable(['number', 'floor', 'room_type_id'])]
#[UsePolicy(InventoryPolicy::class)]
class Room extends Model
{
    use BelongsToProperty, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['deactivated_at' => 'datetime', 'vfms_room_id' => 'integer'];
    }

    protected static function booted(): void
    {
        // Rooms are never deleted, so the highest number is never handed out again; CreateRoom's entitlement lock serializes this.
        static::creating(fn (self $room) => $room->vfms_room_id ??= (int) self::query()->where('property_id', $room->property_id)->max('vfms_room_id') + 1);
        static::updating(function (self $room): void {
            if ($room->isDirty('vfms_room_id')) {
                throw new LogicException('A VFMS room number is never edited.');
            }
        });
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('deactivated_at'));
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<OutOfServicePeriod, $this>
     */
    public function outOfServicePeriods(): HasMany
    {
        return $this->hasMany(OutOfServicePeriod::class);
    }
}
