<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\RatePlan;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class CreateRatePlan
{
    /**
     * A derived plan names an active, non-derived base plan and takes its currency and room types.
     *
     * @param  array{name: string, code: string, currency?: string, room_type_ids?: list<string>, base_rate_plan_id?: string, adjustment_kind?: string, adjustment?: int, cancellation_free_days?: int|null, cancellation_charge?: string|null, cancellation_percent?: int|null, no_show_charge?: string|null, no_show_percent?: int|null}  $details
     */
    public function handle(?User $actor, array $details): RatePlan
    {
        return DB::transaction(function () use ($actor, $details) {
            $details = [...$details, ...RatePlan::policyAttributes($details)];
            if (isset($details['base_rate_plan_id'])) {
                // Locked so the base can't be deactivated while a plan derives from it.
                $base = RatePlan::query()->whereKey($details['base_rate_plan_id'])->bases()->lockForUpdate()->firstOrFail();
                $details = [...$details, 'currency' => $base->currency->value, 'room_type_ids' => $base->roomTypes()->pluck('room_types.id')->all()];
            }
            $plan = RatePlan::query()->create(collect($details)->except('room_type_ids')->all());
            $plan->roomTypes()->sync($details['room_type_ids'] ?? []);
            Audit::record($actor, 'created', $plan, 'Rate plan created', ['attributes' => $details]);

            return $plan;
        });
    }
}
