<?php

namespace App\Domains\Tenancy\Data;

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Support\Money\Currency;

/**
 * A property's own details, as entered on a property form.
 *
 * Zanzibar-only fields (the ZRA hotel code) are dropped for any other
 * jurisdiction profile, so they are never stored on a mainland property.
 */
final readonly class PropertyDetails
{
    public ?string $zraHotelCode;

    public function __construct(
        public string $name,
        public PropertyType $type,
        public string $addressLine,
        public string $city,
        public string $region,
        public ?string $postalCode,
        public string $phone,
        public string $email,
        public string $timezone,
        public Currency $currency,
        public JurisdictionProfile $jurisdictionProfile,
        ?string $zraHotelCode = null,
        public ?bool $housekeepingInspectionRequired = null,
        /** @var list<string>|null */
        public ?array $registrationFields = null,
    ) {
        $this->zraHotelCode = JurisdictionRules::hasZraHotelCode($jurisdictionProfile) && filled($zraHotelCode)
            ? $zraHotelCode
            : null;
    }

    /**
     * @param  array<string, mixed>  $input  validated form input
     */
    public static function fromArray(array $input, string $nameKey = 'name'): self
    {
        return new self(
            name: self::string($input, $nameKey),
            type: PropertyType::from(self::string($input, 'property_type')),
            addressLine: self::string($input, 'address_line'),
            city: self::string($input, 'city'),
            region: self::string($input, 'region'),
            postalCode: self::optionalString($input, 'postal_code'),
            phone: self::string($input, 'phone'),
            email: self::string($input, 'email'),
            timezone: self::string($input, 'timezone'),
            currency: Currency::from(self::string($input, 'currency')),
            jurisdictionProfile: JurisdictionProfile::from(self::string($input, 'jurisdiction_profile')),
            zraHotelCode: self::optionalString($input, 'zra_hotel_code'),
            housekeepingInspectionRequired: array_key_exists('housekeeping_inspection_required', $input) ? (bool) $input['housekeeping_inspection_required'] : null,
            registrationFields: is_array($input['registration_fields'] ?? null) ? array_values(array_map(strval(...), $input['registration_fields'])) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'property_type' => $this->type,
            'address_line' => $this->addressLine,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postalCode,
            'phone' => $this->phone,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'currency' => $this->currency,
            'jurisdiction_profile' => $this->jurisdictionProfile,
            'zra_hotel_code' => $this->zraHotelCode,
            ...($this->housekeepingInspectionRequired !== null ? ['housekeeping_inspection_required' => $this->housekeepingInspectionRequired] : []),
            // Only a profile that lets the property choose stores a choice.
            ...(! JurisdictionRules::configuresRegistrationFields($this->jurisdictionProfile) ? ['registration_fields' => null] : ($this->registrationFields !== null ? ['registration_fields' => $this->registrationFields] : [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function string(array $input, string $key): string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private static function optionalString(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
