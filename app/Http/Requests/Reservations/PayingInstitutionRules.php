<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Enums\PayingInstitutionType;
use Illuminate\Validation\Rule;

trait PayingInstitutionRules
{
    /** @return array<string, mixed> */
    protected function payingInstitutionRules(): array
    {
        return [
            'paying_institution_type' => ['nullable', Rule::enum(PayingInstitutionType::class)],
            'paying_institution_name' => ['exclude_without:paying_institution_type', 'required', 'string', 'max:255'],
            'paying_institution_zrb_number' => ['exclude_without:paying_institution_type', 'required', 'string', 'max:255'],
        ];
    }
}
