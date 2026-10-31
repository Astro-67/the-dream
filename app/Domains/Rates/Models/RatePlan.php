<?php

namespace App\Domains\Rates\Models;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Enums\AdjustmentKind;
use App\Domains\Rates\Enums\FeeCharge;
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
 * @property int|null $cancellation_free_days cancelling at least this many days before arrival is free; null: never free
 * @property FeeCharge|null $cancellation_charge null: cancelling never costs anything
 * @property int|null $cancellation_percent whole percent, for FeeCharge::Percent
 * @property FeeCharge|null $no_show_charge
 * @property int|null $no_show_percent
 * @property-read RatePlan|null $basePlan
 */
#[Fillable(['name', 'code', 'currency', 'base_rate_plan_id', 'adjustment_kind', 'adjustment', ...self::POLICY])]
#[UsePolicy(RatePlanPolicy::class)]
class RatePlan extends Model
{
    use BelongsToProperty, HasUlids;

    /** The cancellation policy and no-show fee fields. */
    public const array POLICY = ['cancellation_free_days', 'cancellation_charge', 'cancellation_percent', 'no_show_charge', 'no_show_percent'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency' => Currency::class, 'adjustment_kind' => AdjustmentKind::class, 'adjustment' => 'integer', 'deactivated_at' => 'immutable_datetime',
            'cancellation_free_days' => 'integer', 'cancellation_charge' => FeeCharge::class, 'cancellation_percent' => 'integer', 'no_show_charge' => FeeCharge::class, 'no_show_percent' => 'integer'];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, int|string|null>
     */
    public static function policyAttributes(array $values): array
    {
        $value = fn (string $key) => ($attribute = $values[$key] ?? null) instanceof \BackedEnum ? $attribute->value : $attribute;
        $int = fn (string $key) => $value($key) === null ? null : (int) $value($key);

        return [
            'cancellation_free_days' => $int('cancellation_free_days'),
            'cancellation_charge' => $value('cancellation_charge'),
            'cancellation_percent' => $value('cancellation_charge') === 'percent' ? $int('cancellation_percent') : null,
            'no_show_charge' => $value('no_show_charge'),
            'no_show_percent' => $value('no_show_charge') === 'percent' ? $int('no_show_percent') : null,
        ];
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
