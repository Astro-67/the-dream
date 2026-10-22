<?php

namespace App\Domains\Rates;

use App\Domains\Rates\Enums\TaxAppliesTo;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Whether VAT and the infrastructure levy sit inside the room rate. Read from the room-covering
 * rules in force on the business date: TaxRule.inclusive is the only authority, nothing is stored here.
 */
final class TaxInclusion
{
    public const array CONTROLS = ['vat', 'levy'];

    /**
     * Rules in force today and later versions, of the rules a control covers.
     *
     * @return array{current: Collection<int, TaxRule>, pending: Collection<int, TaxRule>}
     */
    public static function rules(Property $property, string $control): array
    {
        $date = $property->business_date->toDateString();
        $rules = TaxRule::query()->where('property_id', $property->id)->orderBy('name')->orderBy('effective_from')->get()
            ->filter(fn (TaxRule $rule) => $rule->applies_to !== TaxAppliesTo::Extras && ($control === 'vat' ? $rule->isVat() : $rule->isInfrastructureLevy()))
            ->values();

        return [
            'current' => $rules->filter(fn (TaxRule $rule) => $rule->coversDate($date))->values(),
            'pending' => $rules->filter(fn (TaxRule $rule) => $rule->effective_from->format('Y-m-d') > $date)->values(),
        ];
    }

    /** @param Collection<int, TaxRule> $rules  'none' when no rule covers the room rate, so staff configure taxes instead */
    public static function state(Collection $rules): string
    {
        return match ($rules->pluck('inclusive')->unique()->values()->all()) {
            [] => 'none', [true] => 'included', [false] => 'added', default => 'mixed',
        };
    }

    /**
     * The first date a change can start: the business date, and after every version it replaces starts (one a day).
     *
     * @param  Collection<int, TaxRule>  $rules
     */
    public static function earliest(Property $property, Collection $rules): string
    {
        return $rules->map(fn (TaxRule $rule) => $rule->effective_from->addDay()->format('Y-m-d'))
            ->push($property->business_date->toDateString())->max();
    }

    /**
     * Each control for the property settings form.
     *
     * @return array<string, array{state: string, covers_extras: bool, earliest: string, pending: list<array{name: string, inclusive: bool, effective_from: string}>}>
     */
    public static function of(Property $property): array
    {
        // The property form is organization-level; rules are read in the property they belong to.
        return Tenancy::within(TenantContext::forProperty($property), fn () => collect(self::CONTROLS)->mapWithKeys(function (string $control) use ($property) {
            ['current' => $current, 'pending' => $pending] = self::rules($property, $control);

            return [$control => [
                'state' => self::state($current),
                'covers_extras' => $current->contains(fn (TaxRule $rule) => $rule->applies_to === TaxAppliesTo::Both),
                'earliest' => self::earliest($property, $current),
                'pending' => $pending->map(fn (TaxRule $rule) => ['name' => $rule->name, 'inclusive' => $rule->inclusive, 'effective_from' => $rule->effective_from->format('Y-m-d')])->all(),
            ]];
        })->all());
    }
}
