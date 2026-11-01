<?php

namespace App\Domains\Rates\Models;

use App\Domains\Rates\Enums\TaxAppliesTo;
use App\Domains\Rates\Enums\TaxKind;
use App\Domains\Rates\Enums\TaxRounding;
use App\Domains\Rates\Enums\ZraTaxCategory;
use App\Domains\Rates\Policies\TaxRulePolicy;
use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * One tax or service charge of a property over its effective dates, both ends included.
 * Rules sharing a name never overlap: a change ends one and starts the next.
 *
 * @property ZraTaxCategory|null $zra_tax_category
 * @property string $id
 * @property string $name
 * @property TaxKind $kind
 * @property int|null $rate basis points of the charge, for a percentage
 * @property int|null $amount minor units of $currency per guest per night, for a flat rule
 * @property Currency|null $currency
 * @property bool $inclusive
 * @property TaxAppliesTo $applies_to
 * @property TaxRounding $rounding
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 */
#[Fillable(['zra_tax_category', 'name', 'kind', 'rate', 'amount', 'currency', 'inclusive', 'applies_to', 'rounding', 'effective_from', 'effective_to'])]
#[UsePolicy(TaxRulePolicy::class)]
class TaxRule extends Model
{
    use BelongsToProperty, HasUlids;

    protected static function booted(): void
    {
        // ZRA adds its levy on top of the room price at check-out, so a levy inside the price would undercharge every guest.
        static::saving(function (self $rule): void {
            if ($rule->zra_tax_category === ZraTaxCategory::HotelLevy && $rule->inclusive && (! $rule->exists || $rule->isDirty(['inclusive', 'zra_tax_category']))) {
                throw ValidationException::withMessages(['inclusive' => __('The infrastructure levy is always added on top of the room price, as ZRA charges it.')]);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'zra_tax_category' => ZraTaxCategory::class,
            'kind' => TaxKind::class, 'rate' => 'integer', 'amount' => 'integer', 'currency' => Currency::class,
            'inclusive' => 'boolean', 'applies_to' => TaxAppliesTo::class, 'rounding' => TaxRounding::class,
            'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date',
        ];
    }

    public function isInfrastructureLevy(): bool
    {
        return $this->name === 'Infrastructure levy' && $this->zra_tax_category === ZraTaxCategory::HotelLevy
            && $this->kind === TaxKind::FlatPerPersonNight && $this->currency === Currency::USD;
    }

    public function isVat(): bool
    {
        return $this->zra_tax_category === ZraTaxCategory::Vat && $this->kind === TaxKind::Percentage;
    }

    /**
     * The rule's terms as ChangeTaxRule takes them, for a next version that changes only some.
     *
     * @return array{zra_tax_category: string|null, name: string, kind: string, rate: int|null, amount: int|null, currency: string|null, inclusive: bool, applies_to: string, rounding: string, effective_from: string, effective_to: string|null}
     */
    public function terms(): array
    {
        return [
            'zra_tax_category' => $this->zra_tax_category?->value, 'name' => $this->name, 'kind' => $this->kind->value,
            'rate' => $this->rate, 'amount' => $this->amount, 'currency' => $this->currency?->value, 'inclusive' => $this->inclusive,
            'applies_to' => $this->applies_to->value, 'rounding' => $this->rounding->value,
            'effective_from' => $this->effective_from->format('Y-m-d'), 'effective_to' => $this->effective_to?->format('Y-m-d'),
        ];
    }

    /** Whether the rule is in force on a Y-m-d date. */
    public function coversDate(string $date): bool
    {
        return $this->effective_from->format('Y-m-d') <= $date && ($this->effective_to === null || $date <= $this->effective_to->format('Y-m-d'));
    }

    /**
     * Stored as a plain Y-m-d, so date strings match it on every database (SQLite would add a time).
     *
     * @return Attribute<CarbonImmutable, mixed>
     */
    protected function effectiveFrom(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $this->asDate($value)->format('Y-m-d'));
    }

    /** @return Attribute<CarbonImmutable|null, mixed> */
    protected function effectiveTo(): Attribute
    {
        return Attribute::set(fn (mixed $value) => $value === null ? null : $this->asDate($value)->format('Y-m-d'));
    }
}
