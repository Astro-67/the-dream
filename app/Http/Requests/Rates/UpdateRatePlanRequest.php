<?php

namespace App\Http\Requests\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\Rule;

class UpdateRatePlanRequest extends RatePlanRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('ratePlan')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var RatePlan $plan */
        $plan = $this->route('ratePlan');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16', Rule::unique('rate_plans', 'code')->where('property_id', Tenancy::property()?->id)->ignore($plan->id)],
            ...($plan->base_rate_plan_id !== null ? $this->adjustmentRules(false) : [
                'room_type_ids' => ['required', 'array', 'min:1'],
                'room_type_ids.*' => ['required', 'string', 'distinct', Rule::in(RoomType::query()->pluck('id')->all())],
            ]),
            ...$this->policyRules(),
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['room_type_ids' => __('room types'), 'room_type_ids.*' => __('room type')];
    }
}
