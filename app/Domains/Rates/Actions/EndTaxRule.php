<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets the last date a rule is in force. Rules are never deleted, so earlier dates keep their tax.
 */
class EndTaxRule
{
    public function handle(?User $actor, TaxRule $rule, string $to): TaxRule
    {
        return Audit::validationRefusal($actor, $rule, 'Tax rule end refused', fn () => DB::transaction(function () use ($actor, $rule, $to) {
            Property::query()->whereKey($rule->property_id)->lockForUpdate()->firstOrFail();
            $rule = TaxRule::query()->whereKey($rule->id)->firstOrFail();
            $from = $rule->effective_from->format('Y-m-d');
            if ($to < $from) {
                throw ValidationException::withMessages(['effective_to' => __('A rule cannot end before it starts on :from.', ['from' => $from])]);
            }
            CreateTaxRule::refuseOverlap($rule->name, $from, $to, $rule, 'effective_to');
            $old = $rule->effective_to?->format('Y-m-d');
            $rule->effective_to = $to;
            $rule->save();
            Audit::record($actor, 'updated', $rule, 'Tax rule ended', ['old' => ['effective_to' => $old], 'attributes' => ['effective_to' => $to]]);

            return $rule;
        }));
    }
}
