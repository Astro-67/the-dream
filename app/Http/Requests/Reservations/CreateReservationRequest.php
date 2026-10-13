<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Guests\Models\Booker;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use App\Support\Tenancy\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateReservationRequest extends FormRequest
{
    use AuditsRefusals, StayRules;

    public function authorize(): bool
    {
        return $this->user()?->can('create', Reservation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->stayRules();
        // ponytail: Spec 7 will provide the open business date.
        $rules['arrival'][] = 'after_or_equal:'.now(Tenancy::property()?->timezone)->toDateString();

        return [
            ...$rules,
            'rooms' => ['sometimes', 'integer', 'between:1,20'],
            'booker_id' => [$this->isMethod('GET') ? 'nullable' : 'required', 'string', Rule::in(Booker::query()->pluck('id')->all())],
            'source' => [$this->isMethod('GET') ? 'nullable' : 'required', Rule::in(['direct', 'walk-in', 'phone', 'outside-integration'])],
            'channel_reference' => ['exclude_unless:source,outside-integration', $this->isMethod('GET') ? 'nullable' : 'required', 'string', 'max:255'],
            'outside_integration_reason' => ['exclude_unless:source,outside-integration', $this->isMethod('GET') ? 'nullable' : 'required', 'string', 'max:2000'],
            'payment_collector' => ['sometimes', Rule::in(['property', 'channel'])],
            'channel_collected_amount' => ['exclude_unless:payment_collector,channel', $this->isMethod('GET') ? 'nullable' : 'required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
