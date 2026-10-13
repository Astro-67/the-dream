<?php

namespace App\Domains\Rates\Actions;

use App\Domains\Rates\Models\RatePlan;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class UpdateRatePlan
{
    /** @param array{name: string, code: string, room_type_ids?: list<string>, cancellation_free_days?: int|null, cancellation_charge?: string|null, cancellation_percent?: int|null, no_show_charge?: string|null, no_show_percent?: int|null} $details */
    public function handle(?User $actor, RatePlan $plan, array $details): RatePlan
    {
        return DB::transaction(function () use ($actor, $plan, $details) {
            $plan = RatePlan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $old = [...$plan->only(['name', 'code']), ...self::policy($plan->only(RatePlan::POLICY)), 'room_type_ids' => $plan->roomTypes()->orderBy('room_types.id')->pluck('room_types.id')->all()];
            $ids = $plan->base_rate_plan_id === null ? $details['room_type_ids'] : $plan->basePlan->roomTypes()->pluck('room_types.id')->all();
            sort($ids);
            $policy = self::policy($details);
            $attributes = ['name' => $details['name'], 'code' => $details['code'], ...$policy, 'room_type_ids' => $ids];
            if ($attributes === $old) {
                return $plan;
            }
            $plan->fill(['name' => $details['name'], 'code' => $details['code'], ...$policy])->save();
            $plan->roomTypes()->sync($ids);
            // Derived plans keep their base's room types, including after the base is edited.
            foreach ($plan->derivedPlans()->get() as $derived) {
                $derived->roomTypes()->sync($ids);
            }
            Audit::record($actor, 'updated', $plan, 'Rate plan updated', ['old' => $old, 'attributes' => $attributes]);

            return $plan;
        });
    }

    /**
     * The policy fields as stored: a percentage only for a percentage charge.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, int|string|null>
     */
    private static function policy(array $values): array
    {
        $value = fn (string $key) => ($v = $values[$key] ?? null) instanceof \BackedEnum ? $v->value : $v;
        $int = fn (string $key) => $value($key) === null ? null : (int) $value($key);

        return [
            'cancellation_free_days' => $int('cancellation_free_days'),
            'cancellation_charge' => $value('cancellation_charge'),
            'cancellation_percent' => $value('cancellation_charge') === 'percent' ? $int('cancellation_percent') : null,
            'no_show_charge' => $value('no_show_charge'),
            'no_show_percent' => $value('no_show_charge') === 'percent' ? $int('no_show_percent') : null,
        ];
    }
}
