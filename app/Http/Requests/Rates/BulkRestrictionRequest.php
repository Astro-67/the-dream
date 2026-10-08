<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkRestrictionRequest extends FormRequest
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
            'room_type_id' => ['required', 'string', Rule::in($plan->roomTypes()->pluck('room_types.id')->all())],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', ...($from ? ['before_or_equal:'.$from->addDays(730)->format('Y-m-d')] : [])],
            'weekdays' => ['required', 'array', 'min:1', 'max:7'],
            'weekdays.*' => ['required', 'integer', 'between:1,7', 'distinct'],
            'min_stay' => ['required', 'integer', 'between:1,365'],
            // Unchecked boxes are absent from a form post: absent means off.
            'closed_to_arrival' => ['sometimes', 'boolean'],
            'stop_sell' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{room_type_id: string, from: string, to: string, weekdays: list<int>, min_stay: int, closed_to_arrival: bool, stop_sell: bool} */
    public function details(): array
    {
        /** @var array{room_type_id: string, from: string, to: string, weekdays: list<int|string>, min_stay: int|string} $valid */
        $valid = $this->validated();

        return [
            'room_type_id' => $valid['room_type_id'], 'from' => $valid['from'], 'to' => $valid['to'],
            'weekdays' => array_map(intval(...), $valid['weekdays']), 'min_stay' => (int) $valid['min_stay'],
            'closed_to_arrival' => $this->boolean('closed_to_arrival'), 'stop_sell' => $this->boolean('stop_sell'),
        ];
    }
}
