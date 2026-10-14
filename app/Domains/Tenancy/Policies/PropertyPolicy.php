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
 * - properties.manage (owner, group operations manager): add properties to the
 *   organization, and see its archived properties.
 * - properties.update (also property managers): edit a property they may act in.
 * - properties.archive (owners only): archive a property, and restore one.
 * - properties.update also takes a property live; tax.manage marks its taxes reviewed.
 */
class PropertyPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesView);
    }

    /**
     * Archived property history is visible to any member who can view properties.
     */
    public function viewArchived(User $user): bool
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

    public function archive(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesArchive, $property);
    }

    /**
     * Checked organization-wide: no one may act in an archived property until it is restored.
     */
    public function restore(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesArchive);
    }

    public function goLive(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::PropertiesUpdate, $property);
    }

    public function reviewTaxes(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::TaxManage, $property);
    }
}
