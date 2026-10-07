<?php

namespace Database\Factories;

use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Models\Property;
use App\Support\Money\Currency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Properties are tenant-owned: create them inside a tenant context, for
 * example inTenant($organization, fn () => Property::factory()->create()).
 *
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Hotel',
            'property_type' => PropertyType::Hotel,
            'address_line' => fake()->streetAddress(),
            'city' => 'Zanzibar City',
            'region' => 'Mjini Magharibi',
            'postal_code' => null,
            'phone' => '+255 24 000 0000',
            'email' => fake()->safeEmail(),
            'timezone' => 'Africa/Dar_es_Salaam',
            'currency' => Currency::TZS,
            'jurisdiction_profile' => JurisdictionProfile::Zanzibar,
        ];
    }

    public function mainland(): static
    {
        return $this->state(fn () => [
            'jurisdiction_profile' => JurisdictionProfile::MainlandTanzania,
            'city' => 'Arusha',
            'region' => 'Arusha',
        ]);
    }
}
