<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Inventory\Policies\InventoryPolicy;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of room a property sells, with its occupancy limits.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $base_occupancy
 * @property int $max_adults
 * @property int $max_children
 * @property int $max_occupancy
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'code', 'description', 'base_occupancy', 'max_adults', 'max_children', 'max_occupancy'])]
#[UsePolicy(InventoryPolicy::class)]
class RoomType extends Model
{
    use BelongsToProperty, HasUlids;

    public const FIELDS = ['name', 'code', 'description', 'base_occupancy', 'max_adults', 'max_children', 'max_occupancy'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_occupancy' => 'integer',
            'max_adults' => 'integer',
            'max_children' => 'integer',
            'max_occupancy' => 'integer',
        ];
    }

    public function overbookingAllowance(): int
    {
        $percentage = Property::query()->findOrFail($this->property_id)->settings()['front_desk_settings']['maximum_overbooking_percentage'];

        return (int) floor($this->rooms()->active()->count() * $percentage / 100);
    }

    /**
     * @return HasMany<Room, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }
}
