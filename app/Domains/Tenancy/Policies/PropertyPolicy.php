<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Who may add and edit the current organization's properties: members whose
 * role grants properties.manage (owner, group operations manager). Editing also
 * needs the member to be allowed to act in that property.
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('properties.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('properties.manage');
    }

    public function update(User $user, Property $property): bool
    {
        return $user->can('properties.manage')
            && Tenancy::allows($user, TenantContext::forProperty($property));
    }
}
