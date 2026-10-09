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
        $from = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->value()) ?: null, report: false);

        return [
            // A derived plan's prices come from its base plan.
            'room_type_id' => ['required', 'string', ...($plan->base_rate_plan_id === null ? [Rule::in($plan->roomTypes()->pluck('room_types.id')->all())] : ['prohibited'])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', ...($from ? ['before_or_equal:'.$from->addDays(730)->format('Y-m-d')] : [])],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'base_price' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'extra_adult' => ['required', 'integer', 'min:0', 'max:1000000000'],
            'extra_child' => ['required', 'integer', 'min:0', 'max:1000000000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['room_type_id.prohibited' => __('A derived plan takes its prices from its base plan.')];
    }

    /** @return array{room_type_id: string, from: string, to: string, weekdays: list<int>, base_price: int, extra_adult: int, extra_child: int} */
    public function details(): array
    {
        /** @var array{room_type_id: string, from: string, to: string, weekdays: list<int|string>, base_price: int|string, extra_adult: int|string, extra_child: int|string} $valid */
        $valid = $this->validated();

        return [
            'room_type_id' => $valid['room_type_id'], 'from' => $valid['from'], 'to' => $valid['to'], 'weekdays' => array_map(intval(...), $valid['weekdays']),
            'base_price' => (int) $valid['base_price'], 'extra_adult' => (int) $valid['extra_adult'], 'extra_child' => (int) $valid['extra_child'],
        ];
    }
}
