<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\Country;
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
            ...PropertyRequest::propertyRules(),
        ];
    }

    public function setup(): OrganizationSetup
    {
        return OrganizationSetup::fromArray($this->validated());
    }
}
