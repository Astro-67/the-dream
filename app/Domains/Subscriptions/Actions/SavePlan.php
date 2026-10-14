<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates or edits a plan. Platform data, so it is logged under `platform`.
 * Marking a plan default unmarks the previous one. Editing limits never
 * touches anyone's properties or rooms (SUB-03).
 */
class SavePlan
{
    /** @param array<string, mixed> $details validated Plan::FIELDS */
    public function handle(User $actor, array $details, ?Plan $plan = null): Plan
    {
        return DB::transaction(function () use ($actor, $details, $plan) {
            $plan ??= new Plan(['modules' => []]);
            $old = $plan->exists ? $plan->only(array_keys($details)) : [];
            if ($plan->is_default && array_key_exists('is_default', $details) && ! $details['is_default']) {
                // New organizations need a plan to start their trial on (StartTrial).
                throw ValidationException::withMessages(['is_default' => __('Make another plan the default first.')]);
            }
            if (! empty($details['is_default'])) {
                Plan::query()->where('is_default', true)->whereKeyNot($plan->id)->update(['is_default' => false]);
            }
            $plan->fill($details)->save();

            activity('platform')->causedBy($actor)->performedOn($plan)->event($old === [] ? 'plan_created' : 'plan_updated')
                ->withProperties(['old' => $old, 'attributes' => $plan->only(array_keys($details))])
                ->log($old === [] ? 'Plan created' : 'Plan updated');

            return $plan;
        });
    }
}
