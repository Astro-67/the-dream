<?php

namespace Database\Factories;

use App\Domains\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A bare organization, with no owner or properties. To get a usable tenant,
 * use the organizationWithOwner() Pest helper, which runs the real setup action.
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'country' => 'TZ',
        ];
    }
}
