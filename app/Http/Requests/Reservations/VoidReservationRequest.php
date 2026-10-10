<?php

namespace App\Http\Requests\Reservations;

use Illuminate\Foundation\Http\FormRequest;

class VoidReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('void', $this->route('reservation')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:2000']];
    }
}
