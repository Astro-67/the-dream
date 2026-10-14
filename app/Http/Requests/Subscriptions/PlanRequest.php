<?php

namespace App\Http\Requests\Subscriptions;

use App\Domains\Subscriptions\Enums\BillingPeriod;
use App\Domains\Subscriptions\Models\Plan;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Plan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $plan = $this->route('plan');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('plans', 'code')->ignore($plan instanceof Plan ? $plan->id : null)],
            'property_limit' => ['required', 'integer', 'min:1', 'max:10000'],
            'room_limit' => ['required', 'integer', 'min:1', 'max:100000'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'billing_period' => ['required', Rule::enum(BillingPeriod::class)],
            'price' => ['required', 'integer', 'min:0'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
