<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\RatePlan;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class CreateRatePlan
{
    /** @param array{name: string, code: string, currency: string, room_type_ids: list<string>} $details */
    public function handle(?User $actor, array $details): RatePlan
    {
        return DB::transaction(function () use ($actor, $details) {
            $plan = RatePlan::query()->create(collect($details)->except('room_type_ids')->all());
            $plan->roomTypes()->sync($details['room_type_ids']);
            Audit::record($actor, 'created', $plan, 'Rate plan created', ['attributes' => $details]);

            return $plan;
        });
    }
}
