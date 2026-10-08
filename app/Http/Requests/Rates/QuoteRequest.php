<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('ratePlan')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var RatePlan $plan */
        $plan = $this->route('ratePlan');
        $arrival = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('arrival')->value()) ?: null, report: false);

        // Every field is optional together: an empty form shows no quote yet.
        return [
            'room_type_id' => ['required_with:arrival,departure', 'string', Rule::in($plan->roomTypes()->pluck('room_types.id')->all())],
            'arrival' => ['required_with:room_type_id', 'date_format:Y-m-d'],
            'departure' => ['required_with:room_type_id', 'date_format:Y-m-d', 'after:arrival', ...($arrival ? ['before_or_equal:'.$arrival->addDays(365)->format('Y-m-d')] : [])],
            'adults' => ['required_with:room_type_id', 'integer', 'between:1,20'],
            'children' => ['required_with:room_type_id', 'integer', 'between:0,20'],
        ];
    }
}
