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
}
