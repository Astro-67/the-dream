<?php

namespace Tests\Support;

/**
 * Submissions of the property form (add or edit a property).
 */
final class PropertyForm
{
    /**
     * A valid property form submission: a mainland lodge. Override any field by key.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function payload(array $overrides = []): array
    {
        return [
            'name' => 'Serengeti Gate Lodge',
            'property_type' => 'lodge',
            'address_line' => 'Ngorongoro Road 4',
            'city' => 'Karatu',
            'region' => 'Arusha',
            'postal_code' => '23301',
            'phone' => '+255 27 253 0000',
            'email' => 'stay@serengetigate.example',
            'timezone' => 'Africa/Nairobi',
            'currency' => 'EUR',
            'jurisdiction_profile' => 'mainland_tanzania',
            ...$overrides,
        ];
    }
}
