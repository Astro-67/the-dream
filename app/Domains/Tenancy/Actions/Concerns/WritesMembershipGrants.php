<?php

namespace App\Domains\Tenancy\Actions\Concerns;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Writing a member's role and properties, for the actions that change them.
 * Call inside the organization's context (not a property's), in a transaction.
 */
trait WritesMembershipGrants
{
    /**
     * Locks the organization's memberships for the rest of the transaction, so two
     * owners can't demote or remove each other at once.
     */
    private function lockMemberships(): void
    {
        OrganizationMembership::query()->lockForUpdate()->get();
    }

    private function ownerCount(): int
    {
        return OrganizationMembership::query()
            ->whereHas('user', fn (Builder $user) => $user->role(OrganizationRole::Owner->value))
            ->count();
    }

    private function writeGrant(OrganizationMembership $membership, MembershipGrant $grant): void
    {
        $membership->update(['all_properties' => $grant->allProperties]);

        PropertyMembership::query()->where('user_id', $membership->user_id)->delete();

        if (! $grant->allProperties) {
            foreach ($grant->propertyIds as $propertyId) {
                PropertyMembership::query()->create(['user_id' => $membership->user_id, 'property_id' => $propertyId]);
            }
        }

        /** @var User $member */
        $member = $membership->user;
        $member->unsetRelation('roles')->syncRoles([$grant->role->value]);
    }
}
