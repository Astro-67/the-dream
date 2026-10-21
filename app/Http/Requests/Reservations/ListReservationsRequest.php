<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\ReservationLists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListReservationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Reservation::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'list' => ['sometimes', Rule::in(ReservationLists::FILTERS)],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }
}
