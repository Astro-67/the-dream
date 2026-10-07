<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Access;

/**
 * The current organization's properties.
 *
 * - properties.view: see the properties the member may act in.
 * - properties.manage (owner, group operations manager): add properties to the organization.
 * - properties.update (also property managers): edit a property they may act in.
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesView);
    }

    public function create(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesManage);
    }

    public function update(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesUpdate, $property);
    }
}
