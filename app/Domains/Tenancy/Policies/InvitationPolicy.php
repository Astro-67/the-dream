<?php

namespace App\Domains\Tenancy\Policies;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;

/**
 * Who may invite people to the current organization, and with what grant.
 *
 * - `members.manage` (owners): any role, any properties of the organization, or all properties.
 * - `members.invite` (property managers): property staff roles, only to properties
 *   they may act in themselves, never all properties.
 *
 * Checked against the organization in the tenant context. Which properties exist
 * there is validated by the form request; this decides whether the user may grant them.
 */
class InvitationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->managesMembers($user) || $user->checkPermissionTo('members.invite');
    }

    public function create(User $user, ?MembershipGrant $grant = null): bool
    {
        if ($this->managesMembers($user)) {
            return true;
        }

        if (! $user->checkPermissionTo('members.invite')) {
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
        return $user->checkPermissionTo('members.manage');
    }

    private function withinOwnProperties(User $user, MembershipGrant $grant): bool
    {
        if ($grant->allProperties || ! $grant->role->isPropertyStaff()) {
            return false;
        }

        $own = Tenancy::propertiesFor($user)->map(fn (Property $property) => $property->id)->all();

        return array_diff($grant->propertyIds, $own) === [];
    }

    private function grantOf(Invitation $invitation): MembershipGrant
    {
        return new MembershipGrant($invitation->role, $invitation->all_properties, $invitation->property_ids);
    }
}
