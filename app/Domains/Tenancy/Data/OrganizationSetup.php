<?php

namespace App\Domains\Tenancy\Data;

use App\Domains\Tenancy\Enums\Country;

/**
 * The organization setup form: the organization and its first property.
 */
final readonly class OrganizationSetup
{
    public function __construct(
        public string $organizationName,
        public Country $country,
        public PropertyDetails $property,
        public int $rooms = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated form input
     */
    public static function fromArray(array $input): self
    {
        return new self(
            organizationName: is_string($input['organization_name'] ?? null) ? $input['organization_name'] : '',
            country: Country::from(is_string($input['country'] ?? null) ? $input['country'] : ''),
            property: PropertyDetails::fromArray($input, nameKey: 'property_name'),
            rooms: is_numeric($input['rooms'] ?? null) ? (int) $input['rooms'] : 1,
        );
    }
}
