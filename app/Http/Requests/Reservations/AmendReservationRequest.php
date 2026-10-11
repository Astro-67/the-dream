<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AmendReservationRequest extends FormRequest
{
    use AuditsRefusals, StayRules;

    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('amend', $reservation) ?? false)
            && (! $this->filled('room_id') || $this->user()->can('assignRoom', $reservation));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = $this->stayRules();
        $rules['room_id'][0] = 'present';
        array_unshift($rules['room_id'], 'nullable');
        $rules['pricing'] = ['required', Rule::in(['keep', 'reprice'])];

        return $rules;
    }
}
