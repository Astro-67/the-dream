<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class CorrectPayingInstitutionRequest extends FormRequest
{
    use AuditsRefusals, PayingInstitutionRules;

    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('amend', $reservation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->payingInstitutionRules();
    }
}
