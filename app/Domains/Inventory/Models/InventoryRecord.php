<?php

namespace App\Domains\Inventory\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The single inventory row Spec 4 locks before selling a room type for a night.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $room_type_id
 * @property CarbonImmutable $date
 * @property int $sellable_count
 * @property int $sold_count
 */
#[Fillable(['room_type_id', 'date', 'sellable_count'])]
class InventoryRecord extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'sellable_count' => 'integer', 'sold_count' => 'integer'];
    }
}
