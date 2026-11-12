<?php

namespace App\Http\Requests\Reservations;

use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Support\Tenancy\Tenancy;
use Illuminate\Validation\Rule;

trait PayingInstitutionRules
{
    /** @return array<string, mixed> */
    protected function payingInstitutionRules(): array
    {
        $property = Tenancy::property();
        if ($property === null || ! PayingInstitutionType::offeredAt($property)) {
            return array_fill_keys(['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number'], ['exclude']);
        }

        return [
            'paying_institution_type' => ['nullable', Rule::enum(PayingInstitutionType::class)],
            'paying_institution_name' => ['exclude_without:paying_institution_type', 'required', 'string', 'max:255'],
            'paying_institution_zrb_number' => ['exclude_without:paying_institution_type', 'required', 'string', 'max:255'],
        ];
    }
}
