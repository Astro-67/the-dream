<?php

namespace Tests\Support;

use App\Domains\Tenancy\Actions\AddOrganizationMember;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Data\PropertyDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * One organization of the two-tenant fixture (see twoTenants() in tests/Pest.php):
 * the organization, its two properties, and one member per organization role.
 *
 * - Owner and group operations manager may act in all properties.
 * - Every other role may act in the first property ($property) only, so
 *   $otherProperty is out of their reach.
 *
 * Every name, email and code is prefixed with the label ("Alpha", "Bravo"), so a
 * response can be searched for another tenant's data with markers().
 */
final class TenantFixture
{
    /**
     * @param  array{0: Property, 1: Property}  $properties
     * @param  array<string, User>  $members  keyed by OrganizationRole value
     */
    private function __construct(
        public readonly string $label,
        public readonly Organization $organization,
        public readonly array $properties,
        public readonly Property $property,
        public readonly Property $otherProperty,
        public readonly User $owner,
        private readonly array $members,
    ) {}

    public static function build(string $label): self
    {
        $slug = Str::lower($label);

        // Owners use two-factor authentication, as owner-only actions require (#13).
        $owner = User::factory()->withTwoFactor()->create(['name' => "{$label} Owner", 'email' => "owner@{$slug}.test"]);

        [, $organization, $first] = organizationWithOwner([
            'organization_name' => "{$label} Hotels Group",
            'property_name' => "{$label} Stone Town Inn",
            'email' => "stonetown@{$slug}.test",
            'zra_hotel_code' => 'ZH-'.Str::upper($label),
        ], $owner);

        $second = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(
            organizationSetupPayload([
                'property_name' => "{$label} Arusha Lodge",
                'city' => 'Arusha',
                'region' => 'Arusha',
                'email' => "arusha@{$slug}.test",
                'jurisdiction_profile' => 'mainland_tanzania',
                'zra_hotel_code' => null,
                'payment_settings' => ['use_cashier_shifts' => true],
            ]),
            'property_name',
        )));

        $second->forceFill(['status' => PropertyStatus::Live])->save();

        $members = [OrganizationRole::Owner->value => $owner];

        inTenant($organization, function () use ($label, $slug, $first, &$members) {
            foreach (OrganizationRole::cases() as $role) {
                if ($role === OrganizationRole::Owner) {
                    continue;
                }

                $user = User::factory()->create([
                    'name' => $label.' '.Str::headline($role->value),
                    'email' => "{$role->value}@{$slug}.test",
                ]);

                app(AddOrganizationMember::class)->handle($user, $role === OrganizationRole::GroupOperationsManager
                    ? MembershipGrant::allProperties($role)
                    : MembershipGrant::properties($role, [$first->id]));

                $members[$role->value] = $user;
            }
        });

        return new self(
            label: $label,
            organization: $organization,
            properties: [$first, $second],
            property: $first,
            otherProperty: $second,
            owner: $owner->fresh() ?? $owner,
            members: array_map(fn (User $user) => $user->fresh() ?? $user, $members),
        );
    }

    /**
     * The member holding this role. Each call returns a fresh copy, since spatie
     * caches roles on the user object.
     */
    public function member(OrganizationRole $role): User
    {
        return $this->members[$role->value]->fresh() ?? $this->members[$role->value];
    }

    /**
     * Every member, keyed by role value (owner first).
     *
     * @return array<string, User>
     */
    public function members(): array
    {
        return array_map(fn (User $user) => $user->fresh() ?? $user, $this->members);
    }

    /**
     * Strings that only this tenant's data contains: IDs, names, emails and codes
     * of the organization, its properties and its members. If any of them shows
     * up in another tenant's response, data has leaked.
     *
     * @return list<string>
     */
    public function markers(): array
    {
        $markers = [$this->organization->id, $this->organization->name];

        foreach ($this->properties as $property) {
            array_push($markers, $property->id, $property->name, $property->email);

            if ($property->zra_hotel_code !== null) {
                $markers[] = $property->zra_hotel_code;
            }
        }

        foreach ($this->members as $member) {
            array_push($markers, $member->id, $member->email);
        }

        return $markers;
    }
}
