<?php

namespace App\Http\Requests\Rates;

use App\Domains\Rates\Models\RatePlan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class RateCalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', RatePlan::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $from = rescue(fn () => CarbonImmutable::createFromFormat('!Y-m-d', $this->string('from')->value()) ?: null, report: false);

        // Room type IDs are matched within the current property's scope, so unknown or foreign ones show nothing.
        return [
            'from' => ['required_with:to', 'date_format:Y-m-d'],
            'to' => ['required_with:from', 'date_format:Y-m-d', 'after_or_equal:from', ...($from ? ['before_or_equal:'.$from->addDays(61)->format('Y-m-d')] : [])],
            'room_type_ids' => ['sometimes', 'array', 'max:100'],
            'room_type_ids.*' => ['string'],
        ];
    }
}
