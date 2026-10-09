<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\DateRange;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RateRestriction;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class SetRateRestrictions
{
    /** @param array{room_type_id: string, from: string, to: string, weekdays: list<int>, min_stay: int, closed_to_arrival: bool, stop_sell: bool} $details */
    public function handle(?User $actor, RatePlan $plan, array $details): void
    {
        DB::transaction(function () use ($actor, $plan, $details) {
            // Serialize edits of this plan, including first writes of the same date.
            RatePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $values = collect($details)->only(['min_stay', 'closed_to_arrival', 'stop_sell'])->all();
            foreach (DateRange::dates($details['from'], $details['to'], $details['weekdays']) as $date) {
                RateRestriction::query()->updateOrCreate(['rate_plan_id' => $plan->id, 'room_type_id' => $details['room_type_id'], 'date' => $date], $values);
            }
            Audit::record($actor, 'updated', $plan, 'Restrictions updated', [
                'room_type_id' => $details['room_type_id'], 'from' => $details['from'], 'to' => $details['to'], 'weekdays' => $details['weekdays'], 'attributes' => $values,
            ]);
        });
    }
}
