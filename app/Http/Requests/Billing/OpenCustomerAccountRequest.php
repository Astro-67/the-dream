<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Http\Requests\AuditsRefusals;
use App\Support\Money\Currency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OpenCustomerAccountRequest extends FormRequest
{
    use AuditsRefusals;

    public function authorize(): bool
    {
        return $this->user()?->can('create', CustomerAccount::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['day_use', 'event'])],
            'customer_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'paying_institution_type' => ['nullable', Rule::enum(PayingInstitutionType::class)],
            'paying_institution_name' => ['nullable', 'required_with:paying_institution_type', 'string', 'max:255'],
            'paying_institution_zrb_number' => ['nullable', 'string', 'max:100'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
