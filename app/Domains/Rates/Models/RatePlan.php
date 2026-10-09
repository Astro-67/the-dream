<?php

namespace App\Domains\Rates\Models;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Enums\AdjustmentKind;
use App\Domains\Rates\Policies\RatePlanPolicy;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named way of selling room types at one property in one currency. A derived
 * plan stores no prices: it follows one base plan by an adjustment.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $name
 * @property string $code
 * @property Currency $currency
 * @property string|null $base_rate_plan_id
 * @property AdjustmentKind|null $adjustment_kind
 * @property int|null $adjustment
 * @property CarbonImmutable|null $deactivated_at
 * @property-read RatePlan|null $basePlan
 */
#[Fillable(['name', 'code', 'currency', 'base_rate_plan_id', 'adjustment_kind', 'adjustment'])]
#[UsePolicy(RatePlanPolicy::class)]
class RatePlan extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency' => Currency::class, 'adjustment_kind' => AdjustmentKind::class, 'adjustment' => 'integer', 'deactivated_at' => 'immutable_datetime'];
    }

    /**
     * Plans another plan may derive from: active and not derived themselves, so derivation is one level.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function bases(Builder $query): void
    {
        $query->whereNull($this->qualifyColumn('base_rate_plan_id'))->whereNull($this->qualifyColumn('deactivated_at'));
    }

    /** A base price as this plan sells it: adjusted, unsaved, for a derived plan; unchanged otherwise. */
    public function priced(RatePrice $price): RatePrice
    {
        return $this->adjustment_kind === null ? $price : (clone $price)->forceFill(
            $this->adjustment_kind->apply((int) $this->adjustment, $price->base_price, $price->extra_adult, $price->extra_child),
        );
    }

    /** @return BelongsToMany<RoomType, $this> */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class, 'rate_plan_room_types');
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function basePlan(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_rate_plan_id');
    }

    /** @return HasMany<RatePlan, $this> */
    public function derivedPlans(): HasMany
    {
        return $this->hasMany(self::class, 'base_rate_plan_id');
    }
}
