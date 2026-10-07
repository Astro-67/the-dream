<?php

namespace App\Domains\Tenancy\Data;

use App\Domains\Tenancy\Enums\OrganizationRole;
use InvalidArgumentException;

/**
 * What a membership grants: a role, and either all properties or a list of
 * properties (ADR 0007). Carried by invitations and used to create memberships.
 */
final readonly class MembershipGrant
{
    /**
     * @param  list<string>  $propertyIds  ignored when $allProperties is true
     */
    public function __construct(
        public OrganizationRole $role,
        public bool $allProperties,
        public array $propertyIds = [],
    ) {
        if ($allProperties && ! $role->mayHaveAllProperties()) {
            throw new InvalidArgumentException("The {$role->value} role can't be granted all properties.");
        }

        if (! $allProperties && $propertyIds === []) {
            throw new InvalidArgumentException('A membership without all properties needs at least one property.');
        }
    }

    public static function allProperties(OrganizationRole $role): self
    {
        return new self($role, true);
    }

    /**
     * @param  list<string>  $propertyIds
     */
    public static function properties(OrganizationRole $role, array $propertyIds): self
    {
        return new self($role, false, array_values(array_unique($propertyIds)));
    }

    /**
     * The grant as audit trail values, compared before and after a change.
     *
     * @return array{role: string, all_properties: bool, property_ids: list<string>}
     */
    public function toAuditValues(): array
    {
        $propertyIds = $this->allProperties ? [] : $this->propertyIds;
        sort($propertyIds);

        return ['role' => $this->role->value, 'all_properties' => $this->allProperties, 'property_ids' => $propertyIds];
    }
}
