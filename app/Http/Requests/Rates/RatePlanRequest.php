<?php

namespace App\Http\Requests\Rates;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:16', Rule::unique('rate_plans', 'code')->where('property_id', $property?->id)],
            'currency' => ['required', Rule::in($property ? JurisdictionRules::allowedCurrencies($property) : [])],
            'room_type_ids' => ['required', 'array', 'min:1'],
            'room_type_ids.*' => ['required', 'string', 'distinct', Rule::in(RoomType::query()->pluck('id')->all())],
        ];
    }
}
