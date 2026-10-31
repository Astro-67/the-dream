<?php

namespace App\Http\Requests\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Enums\AdjustmentKind;
use App\Domains\Rates\Enums\FeeCharge;
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
            ...$this->policyRules(),
        ];

        if ($this->filled('base_rate_plan_id')) {
            return [...$common,
                'base_rate_plan_id' => ['required', 'string', Rule::in(RatePlan::query()->bases()->pluck('id')->all())],
                ...$this->adjustmentRules(),
            ];
        }

        return [...$common,
            'currency' => ['required', Rule::in($property ? JurisdictionRules::allowedCurrencies($property) : [])],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['required', 'string', 'distinct', Rule::in(RoomType::query()->pluck('id')->all())],
        ];
    }

    /** @return array<string, mixed> */
    protected function adjustmentRules(bool $required = true): array
    {
        return [
            'adjustment_kind' => [$required ? 'required' : 'required_with:adjustment', Rule::enum(AdjustmentKind::class)],
            'adjustment' => [$required ? 'required' : 'required_with:adjustment_kind', 'integer', $this->input('adjustment_kind') === AdjustmentKind::Percent->value ? 'between:-100,1000' : 'between:-1000000000,1000000000'],
        ];
    }

    /** @return array<string, mixed> */
    protected function policyRules(): array
    {
        return [
            'cancellation_free_days' => ['nullable', 'integer', 'between:0,365'],
            'cancellation_charge' => ['nullable', Rule::enum(FeeCharge::class)],
            'cancellation_percent' => ['nullable', 'required_if:cancellation_charge,percent', 'integer', 'between:1,100'],
            'no_show_charge' => ['nullable', Rule::enum(FeeCharge::class)],
            'no_show_percent' => ['nullable', 'required_if:no_show_charge,percent', 'integer', 'between:1,100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['base_rate_plan_id' => __('base plan')];
    }
}
