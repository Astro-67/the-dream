<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\DateRange;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\RatePrice;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class SetRatePrices
{
    /** @param array{room_type_id: string, from: string, to: string, weekdays: list<int>, base_price: int, extra_adult: int, extra_child: int} $details */
    public function handle(?User $actor, RatePlan $plan, array $details): void
    {
        DB::transaction(function () use ($actor, $plan, $details) {
            // Serialize edits of this plan, including first writes of the same date.
            RatePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $amounts = collect($details)->only(['base_price', 'extra_adult', 'extra_child'])->all();
            foreach (DateRange::dates($details['from'], $details['to'], $details['weekdays']) as $date) {
                RatePrice::query()->updateOrCreate(['rate_plan_id' => $plan->id, 'room_type_id' => $details['room_type_id'], 'date' => $date], $amounts);
            }
            Audit::record($actor, 'updated', $plan, 'Rate prices updated', [
                'room_type_id' => $details['room_type_id'], 'from' => $details['from'], 'to' => $details['to'], 'weekdays' => $details['weekdays'], 'attributes' => $amounts,
            ]);
        });
    }
}
