<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkPriceRequest extends FormRequest
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
            'room_type_id' => ['required', 'string', Rule::in($plan->roomTypes()->pluck('room_types.id')->all())],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.CarbonImmutable::parse($this->input('from', 'today'))->addDays(730)->format('Y-m-d')],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'base_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'extra_adult' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'extra_child' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }
}
