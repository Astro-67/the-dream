<?php

namespace App\Http\Requests\Billing;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Reservations\Enums\PayingInstitutionType;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Requests\AuditsRefusals;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
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
        $rules = [
            'kind' => ['required', Rule::in(['day_use', 'event'])],
            'customer_name' => ['required', 'string', 'max:255'],
            'currency' => ['required', Rule::in(array_map(fn (Currency $currency) => $currency->value, JurisdictionRules::allowedCurrencies(Tenancy::property())))],
            'paying_institution_type' => ['nullable', 'required_with:paying_institution_name,paying_institution_zrb_number', Rule::enum(PayingInstitutionType::class)],
            'paying_institution_name' => ['nullable', 'required_with:paying_institution_type', 'string', 'max:255'],
            'paying_institution_zrb_number' => ['nullable', 'string', 'max:100'],
            'starts_on' => ['nullable', 'required_with:ends_on', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'required_with:starts_on', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'note' => ['nullable', 'string', 'max:5000'],
            'items' => ['sometimes', 'array', 'max:50'],
        ];
        foreach ((new PostExtraChargeRequest)->rules() as $field => $validation) {
            $rules['items.*.'.$field] = $validation;
        }

        return $rules;
    }
}
