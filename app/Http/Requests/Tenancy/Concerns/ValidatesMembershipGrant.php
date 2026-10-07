<?php

namespace App\Http\Requests\Tenancy\Concerns;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The role and properties fields of a membership form (`role`, `all_properties`,
 * `property_ids`), validated against the organization in the tenant context.
 */
trait ValidatesMembershipGrant
{
    /**
     * @return array<string, array<mixed>>
     */
    protected function grantRules(): array
    {
        return [
            'role' => ['required', Rule::enum(OrganizationRole::class)],
            'all_properties' => ['boolean'],
            'property_ids' => ['exclude_if:all_properties,true', 'required', 'array', 'min:1'],
            'property_ids.*' => ['string', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function grantMessages(): array
    {
        return [
            'property_ids.required' => 'Choose at least one property, or all properties.',
        ];
    }

    protected function validateGrant(Validator $validator): void
    {
        $role = OrganizationRole::tryFrom((string) $this->input('role'));
        $allProperties = $this->boolean('all_properties');

        if ($role !== null && $allProperties && ! $role->mayHaveAllProperties()) {
            $validator->errors()->add('all_properties', 'Only owners and group operations managers can be given all properties.');
        }

        if ($role === OrganizationRole::Owner && ! $allProperties) {
            $validator->errors()->add('all_properties', 'Owners work in all properties.');
        }

        $ids = $allProperties ? [] : array_filter((array) $this->input('property_ids', []), 'is_string');

        // Scoped to the current organization, so another organization's property is never accepted.
        if ($ids !== [] && Property::query()->active()->whereKey($ids)->count() !== count(array_unique($ids))) {
            $validator->errors()->add('property_ids', 'Choose properties of this organization.');
        }
    }

    public function grant(): MembershipGrant
    {
        $role = OrganizationRole::from($this->string('role')->toString());

        if ($this->boolean('all_properties')) {
            return MembershipGrant::allProperties($role);
        }

        /** @var list<string> $propertyIds */
        $propertyIds = array_values((array) $this->validated('property_ids'));

        return MembershipGrant::properties($role, $propertyIds);
    }
}
