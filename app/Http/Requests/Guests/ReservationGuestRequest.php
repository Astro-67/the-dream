<?php

namespace App\Http\Requests\Guests;

use App\Domains\Guests\Models\Guest;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Validation\Rule;

class ReservationGuestRequest extends GuestRequest
{
    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('attachGuest', $reservation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        if ($this->filled('guest_id')) {
            return ['guest_id' => ['required', 'string', Rule::in(Guest::query()->pluck('id')->all())], 'name' => ['missing'], 'documents' => ['missing']];
        }

        return ['guest_id' => ['nullable'], ...parent::rules()];
    }
}
