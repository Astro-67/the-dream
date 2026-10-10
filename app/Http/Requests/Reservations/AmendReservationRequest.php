<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Models\Reservation;
use Illuminate\Validation\Rule;

class AmendReservationRequest extends CreateReservationRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('amend', $reservation) ?? false)
            && (! $this->filled('room_id') || ($this->user()?->can('assignRoom', $reservation) ?? false));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['booker_id'], $rules['source'], $rules['payment_collector']);
        $rules['room_id'][0] = 'present';
        array_unshift($rules['room_id'], 'nullable');
        $rules['pricing'] = ['required', Rule::in(['keep', 'reprice'])];

        return $rules;
    }
}
