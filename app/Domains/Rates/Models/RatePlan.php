<?php

namespace App\Domains\Rates\Models;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Policies\RatePlanPolicy;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named way of selling room types at one property in one currency.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $name
 * @property string $code
 * @property Currency $currency
 */
#[Fillable(['name', 'code', 'currency'])]
#[UsePolicy(RatePlanPolicy::class)]
class RatePlan extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency' => Currency::class];
    }

    /** @return BelongsToMany<RoomType, $this> */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'rate_plan_room_types');
    }
}
