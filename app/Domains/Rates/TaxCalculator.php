<?php

namespace App\Domains\Rates;

use App\Domains\Rates\Data\TaxBreakdown;
use App\Domains\Rates\Enums\ChargeKind;
use App\Domains\Rates\Enums\TaxKind;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use InvalidArgumentException;

/**
 * Splits one night's or one extra's charge into net and tax lines. Pure: the same
 * rules and inputs always give the same breakdown, so a stored result never changes.
 */
final class TaxCalculator
{
    /** The breakdown under the property's rules, optionally taking a VAT-inclusive input price. */
    public static function forProperty(Property $property, int $amount, Currency $currency, string $date, int $guests, ChargeKind $charge, bool $inclusivePrice = false): TaxBreakdown
    {
        $rules = TaxRule::query()->where('property_id', $property->id)->orderBy('name')->get()->all();
        if ($inclusivePrice) {
            foreach ($rules as $rule) {
                $rule->inclusive = true;
            }
        }

        return self::breakdown($rules, $amount, $currency, $date, $guests, $charge);
    }

    /**
     * An inclusive rule is inside $amount; an exclusive one is added to it. Percentages
     * apply to the net amount; flat rules charge their amount per guest. Each line is
     * rounded on its own, and rounding of inclusive lines stays in the net amount.
     *
     * @param  iterable<TaxRule>  $rules  every rule of the property; those not in force or not covering the charge are skipped
     * @param  int  $amount  minor units of $currency
     * @param  string  $date  Y-m-d
     *
     * @throws InvalidArgumentException when a flat rule in force is in another currency, or included flat rules exceed the amount
     */
    public static function breakdown(iterable $rules, int $amount, Currency $currency, string $date, int $guests, ChargeKind $charge): TaxBreakdown
    {
        $inForce = collect($rules)->filter(fn (TaxRule $rule) => $rule->coversDate($date) && $rule->applies_to->covers($charge));
        // The seeded USD levy is an estimate; VFMS assesses the actual levy at check-out, so it is left out, not converted.
        $unavailable = $inForce->filter(fn (TaxRule $rule) => $rule->isInfrastructureLevy() && $rule->currency !== $currency);
        $applying = $inForce->diffKeys($unavailable)->values();

        $flat = function (TaxRule $rule) use ($currency, $guests): int {
            if ($rule->currency !== $currency) {
                // ponytail: no exchange rates yet; Spec 6 converts when a folio's currency differs.
                throw new InvalidArgumentException(__(':tax is charged in :rule, not :currency.', ['tax' => $rule->name, 'rule' => $rule->currency?->value, 'currency' => $currency->value]));
            }

            return (int) $rule->amount * $guests;
        };

        $inclusive = $applying->where('inclusive', true);
        $inclusiveFlat = $inclusive->where('kind', TaxKind::FlatPerPersonNight)->sum($flat);
        $inclusiveRate = $inclusive->where('kind', TaxKind::Percentage)->sum('rate');
        $base = max(0, $amount - (int) $inclusiveFlat);
        $denominator = 10000 + (int) $inclusiveRate;

        $lines = $applying->map(function (TaxRule $rule) use ($flat, $base, $denominator): array {
            $isFlat = $rule->kind === TaxKind::FlatPerPersonNight;

            return [
                'name' => $rule->name, 'kind' => $rule->kind->value, 'inclusive' => $rule->inclusive,
                'rate' => $isFlat ? null : $rule->rate, 'per_person_night' => $isFlat ? $rule->amount : null,
                'amount' => $isFlat ? $flat($rule) : $rule->rounding->round($base * (int) $rule->rate, $denominator),
            ];
        })->all();

        $net = $amount - array_sum(array_column(array_filter($lines, fn (array $line) => $line['inclusive']), 'amount'));
        if ($net < 0) {
            throw new InvalidArgumentException(__('The taxes included in this :currency price are more than the price.', ['currency' => $currency->value]));
        }

        return new TaxBreakdown($currency, $net, $lines, $applying->pluck('id')->filter()->values()->all(), $unavailable->pluck('name')->values()->all());
    }
}
