<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a rule from a date: the rule ends the day before and a new version with the
 * same name starts on it. Dates before the change keep the old terms (LOC-02).
 */
class ChangeTaxRule
{
    /** @param array{name: string, kind: string, rate: int|null, amount: int|null, currency: string|null, inclusive: bool, applies_to: string, rounding: string, effective_from: string, effective_to: string|null} $details */
    public function handle(?User $actor, TaxRule $rule, array $details): TaxRule
    {
        return DB::transaction(function () use ($actor, $rule, $details) {
            Property::query()->whereKey($rule->property_id)->lockForUpdate()->firstOrFail();
            $rule = TaxRule::query()->whereKey($rule->id)->firstOrFail();
            $from = $details['effective_from'];
            if ($from <= $rule->effective_from->format('Y-m-d') || ($rule->effective_to !== null && $from > $rule->effective_to->format('Y-m-d'))) {
                throw ValidationException::withMessages(['effective_from' => __('A change must start after :from and while the rule is in force.', ['from' => $rule->effective_from->format('Y-m-d')])]);
            }
            $old = $rule->effective_to?->format('Y-m-d');
            $rule->effective_to = CarbonImmutable::createFromFormat('!Y-m-d', $from)?->subDay();
            $rule->save();
            $details['name'] = $rule->name;
            CreateTaxRule::refuseOverlap($rule->name, $from, $details['effective_to'], $rule);
            $next = TaxRule::query()->create($details);
            Audit::record($actor, 'updated', $rule, 'Tax rule changed', [
                'old' => ['effective_to' => $old], 'attributes' => ['effective_to' => $rule->effective_to?->format('Y-m-d')],
                'replacement_id' => $next->id, 'replacement' => $details,
            ]);

            return $next;
        });
    }
}
