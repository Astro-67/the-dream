<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\Country;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Support\Money\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationRequest extends FormRequest
{
    /**
     * Any signed-in, verified person may set up an organization. Someone who
     * already belongs to one gets that one back (the action is idempotent).
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:255'],
            'country' => ['required', Rule::enum(Country::class)],
            'property_name' => ['required', 'string', 'max:255'],
            'property_type' => ['required', Rule::enum(PropertyType::class)],
            'address_line' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'region' => ['required', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['required', 'email', 'max:255'],
            'timezone' => ['required', 'timezone:all'],
            'currency' => ['required', Rule::enum(Currency::class)],
            'jurisdiction_profile' => ['required', Rule::enum(JurisdictionProfile::class)],
            'zra_hotel_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function setup(): OrganizationSetup
    {
        return OrganizationSetup::fromArray($this->validated());
    }
}
