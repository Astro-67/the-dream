<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\RatePlan;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class UpdateRatePlan
{
    /** @param array{name: string, code: string, room_type_ids?: list<string>} $details */
    public function handle(?User $actor, RatePlan $plan, array $details): RatePlan
    {
        return DB::transaction(function () use ($actor, $plan, $details) {
            $plan = RatePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $old = [...$plan->only(['name', 'code']), 'room_type_ids' => $plan->roomTypes()->orderBy('room_types.id')->pluck('room_types.id')->all()];
            $ids = $plan->base_rate_plan_id === null ? $details['room_type_ids'] : $plan->basePlan->roomTypes()->pluck('room_types.id')->all();
            sort($ids);
            $attributes = ['name' => $details['name'], 'code' => $details['code'], 'room_type_ids' => $ids];
            if ($attributes === $old) {
                return $plan;
            }
            $plan->fill(['name' => $details['name'], 'code' => $details['code']])->save();
            $plan->roomTypes()->sync($ids);
            // Derived plans keep their base's room types, including after the base is edited.
            foreach ($plan->derivedPlans()->get() as $derived) {
                $derived->roomTypes()->sync($ids);
            }
            Audit::record($actor, 'updated', $plan, 'Rate plan updated', ['old' => $old, 'attributes' => $attributes]);

            return $plan;
        });
    }
}
