<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The property form, for adding a property (no route property) or editing one.
 */
class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = $this->route('property');

        return $property instanceof Property
            ? $this->user()?->can('update', $property) ?? false
            : $this->user()?->can('create', Property::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...self::propertyRules(),
            'name' => ['required', 'string', 'max:255'],
            'housekeeping_inspection_required' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function propertyRules(): array
    {
        return [
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

    public function details(): PropertyDetails
    {
        return PropertyDetails::fromArray($this->validated());
    }
}
