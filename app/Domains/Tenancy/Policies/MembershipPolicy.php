<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\Access;

/**
 * The current organization's members.
 *
 * - members.view: see the members who work in a property the viewer may act in
 *   (members with all properties work in every one).
 * - members.manage (owners): see everyone; change roles and properties, remove
 *   members and transfer ownership.
 */
class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::MembersView);
    }

    public function view(User $user, OrganizationMembership $membership): bool
    {
        if ($this->managesMembers($user)) {
            return true;
        }

        $grant = $membership->grant();

        if ($grant->allProperties) {
            return Access::allows($user, OrganizationPermission::MembersView);
        }

        foreach ($grant->propertyIds as $propertyId) {
            if (Access::allows($user, OrganizationPermission::MembersView, $propertyId)) {
                return true;
            }
        }

        return false;
    }

    public function update(User $user, OrganizationMembership $membership): bool
    {
        return $this->managesMembers($user);
    }

    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return $this->managesMembers($user);
    }

    public function transferOwnership(User $user, OrganizationMembership $membership): bool
    {
        return $this->managesMembers($user);
    }

    private function managesMembers(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::MembersManage);
    }
}
