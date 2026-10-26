<?php

namespace App\Http\Requests\FiscalReceipting;

use App\Domains\Reservations\Models\Reservation;
use App\Http\Requests\AuditsRefusals;
use Illuminate\Foundation\Http\FormRequest;

class RecoverVfmsFilingRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        $reservation = $this->route('reservation');

        return $reservation instanceof Reservation && ($this->user()?->can('recoverVfms', $reservation) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'max:500']];
    }
}
