<?php

namespace App\Http\Requests\Tenancy;

use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Data\PropertySettings;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Models\Property;
use App\Support\Audit\Audit;
use App\Support\Money\Currency;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The property form, for adding a property (no route property) or editing one.
 */
class PropertyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $property = $this->route('property');

        if ($this->has('payment_settings.use_cashier_shifts')
            && ! ($this->user()?->can('manageCashierShifts', $property instanceof Property ? $property : Property::class) ?? false)) {
            Audit::record($this->user(), 'refused', $property instanceof Property ? $property : new Property, 'Cashier shift preference change refused', ['result' => 'refused', 'stage' => 'authorization']);

            return false;
        }
        if (! $property instanceof Property) {
            return $this->user()?->can('create', Property::class) ?? false;
        }
        // Tax inclusion changes tax rules, so it needs tax.manage as well as properties.update.
        if ($this->has('tax_inclusion') && ! ($this->user()?->can('manageInclusion', [TaxRule::class, $property]) ?? false)) {
            Audit::record($this->user(), 'refused', $property, 'Tax inclusion change refused', ['result' => 'refused', 'stage' => 'authorization']);

            return false;
        }

        return $this->user()?->can('update', $property) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $property = $this->route('property');

        return [
            ...self::propertyRules(),
            ...PropertySettings::rules(),
            'name' => ['required', 'string', 'max:255'],
            'housekeeping_inspection_required' => ['sometimes', 'boolean'],
            'stayover_service_on_close' => ['sometimes', 'boolean'],
            'registration_fields' => ['sometimes', 'array'],
            'receipt_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Za-z0-9\/-]+$/'],
            'close_time' => ['sometimes', 'date_format:H:i'],
            'registration_fields.*' => ['distinct', Rule::in(IdentityDocument::FIELDS)],
            // Only an edit changes existing tax rules; a new property is seeded with the defaults.
            'tax_inclusion' => [$property instanceof Property ? 'sometimes' : 'prohibited', 'array:vat,effective_from'],
            'tax_inclusion.vat' => ['sometimes', 'nullable', 'boolean'],
            'tax_inclusion.effective_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function propertyRules(): array
    {
        return [
            'star_rating' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:64'],
            'z_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'vrn' => ['sometimes', 'nullable', 'string', 'max:64'],
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

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $property = $this->route('property');
            $defaults = $property instanceof Property ? $property->settings()['front_desk_settings'] : PropertySettings::DEFAULTS['front_desk_settings'];
            $min = $this->input('front_desk_settings.minimum_stay', $defaults['minimum_stay']);
            $max = $this->input('front_desk_settings.maximum_stay', $defaults['maximum_stay']);
            if (is_numeric($min) && is_numeric($max) && $max < $min) {
                $validator->errors()->add('front_desk_settings.maximum_stay', __('Maximum stay must be at least the minimum stay.'));
            }
        }];
    }

    /**
     * The tax inclusion choices, empty when the form leaves taxes alone.
     *
     * @return array{vat?: bool|null, effective_from?: string|null}
     */
    public function taxInclusion(): array
    {
        /** @var array{vat?: bool|string|null, effective_from?: string|null} $choices */
        $choices = $this->validated('tax_inclusion', []);
        $property = $this->route('property');
        if ($choices === [] || ! $property instanceof Property) {
            return [];
        }

        return [
            'vat' => isset($choices['vat']) ? (bool) $choices['vat'] : null,
            'effective_from' => $choices['effective_from'] ?? null,
        ];
    }

    public function details(): PropertyDetails
    {
        return PropertyDetails::fromArray($this->validated());
    }
}
