<?php

namespace App\Http\Requests\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Enums\AdjustmentKind;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A plan with its own currency and room types, or a derived plan naming its base plan and adjustment.
 */
class RatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RatePlan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $property = Tenancy::property();
        $common = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16', Rule::unique('rate_plans', 'code')->where('property_id', $property?->id)],
        ];

        if ($this->filled('base_rate_plan_id')) {
            return [...$common,
                'base_rate_plan_id' => ['required', 'string', Rule::in(RatePlan::query()->bases()->pluck('id')->all())],
                'adjustment_kind' => ['required', Rule::enum(AdjustmentKind::class)],
                'adjustment' => ['required', 'integer', $this->input('adjustment_kind') === AdjustmentKind::Percent->value ? 'between:-100,1000' : 'between:-1000000000,1000000000'],
            ];
        }

        return [...$common,
            'currency' => ['required', Rule::in($property ? JurisdictionRules::allowedCurrencies($property) : [])],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['required', 'string', 'distinct', Rule::in(RoomType::query()->pluck('id')->all())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['base_rate_plan_id' => __('base plan')];
    }
}
