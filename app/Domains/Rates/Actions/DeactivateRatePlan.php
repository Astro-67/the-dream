<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\RatePlan;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stops selling a rate plan. The plan and its prices are kept, so its history stays.
 * Refused while active plans derive from it: they would lose their prices.
 */
class DeactivateRatePlan
{
    public function handle(?User $actor, RatePlan $plan): RatePlan
    {
        return Audit::validationRefusal($actor, $plan, 'Rate plan deactivation refused', fn () => DB::transaction(function () use ($actor, $plan) {
            // Locked so no plan can be derived from it meanwhile (CreateRatePlan locks the base too).
            $plan = RatePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($plan->deactivated_at !== null) {
                return $plan;
            }
            $derived = $plan->derivedPlans()->whereNull('deactivated_at')->orderBy('name')->pluck('name');
            if ($derived->isNotEmpty()) {
                throw ValidationException::withMessages(['plan' => __('Deactivate the plans derived from :plan first: :derived.', ['plan' => $plan->name, 'derived' => $derived->join(', ')])]);
            }
            $plan->deactivated_at = now();
            $plan->save();
            Audit::record($actor, 'deactivated', $plan, 'Rate plan deactivated', [
                'old' => ['deactivated_at' => null],
                'attributes' => ['deactivated_at' => $plan->deactivated_at->toIso8601String()],
            ]);

            return $plan;
        }));
    }
}
