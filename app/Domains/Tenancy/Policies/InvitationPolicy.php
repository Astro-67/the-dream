<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Invitation;
use App\Models\User;
use App\Support\Tenancy\Access;

/**
 * Who may invite people to the current organization, and with what grant.
 *
 * - `members.manage` (owners): any role, any properties of the organization, or all properties.
 * - `members.invite` (property managers): property staff roles, and only to properties
 *   where they hold members.invite themselves (Access), never all properties.
 *
 * Which properties exist is validated by the form request; this decides whether the user may grant them.
 */
class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->managesMembers($user) || Access::allows($user, OrganizationPermission::MembersInvite);
    }

    public function create(User $user, ?MembershipGrant $grant = null): bool
    {
        if ($this->managesMembers($user)) {
            return true;
        }

        if (! Access::allows($user, OrganizationPermission::MembersInvite)) {
            return false;
        }

        return $grant === null || $this->withinOwnProperties($user, $grant);
    }

    /**
     * Seeing a pending invitation in the list follows the same rule as sending it.
     */
    public function view(User $user, Invitation $invitation): bool
    {
        return $this->create($user, $this->grantOf($invitation));
    }

    public function revoke(User $user, Invitation $invitation): bool
    {
        return $this->view($user, $invitation);
    }

    private function managesMembers(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::MembersManage);
    }

    private function withinOwnProperties(User $user, MembershipGrant $grant): bool
    {
        if ($grant->allProperties || ! $grant->role->isPropertyStaff()) {
            return false;
        }

        foreach ($grant->propertyIds as $propertyId) {
            if (! Access::allows($user, OrganizationPermission::MembersInvite, $propertyId)) {
                return false;
            }
        }

        return true;
    }

    private function grantOf(Invitation $invitation): MembershipGrant
    {
        return new MembershipGrant($invitation->role, $invitation->all_properties, $invitation->property_ids);
    }
}
