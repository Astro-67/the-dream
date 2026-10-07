<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Exceptions\MembershipRefused;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Makes a user a member of the organization in the current tenant context: the
 * organization membership, the spatie role in the organization's team, and a
 * property membership per granted property (ADR 0007). Used by accepting an
 * invitation, and by anything else that adds members.
 */
class AddOrganizationMember
{
    public function handle(User $member, MembershipGrant $grant, ?User $actor = null): OrganizationMembership
    {
        $organization = Tenancy::organization() ?? throw MissingTenantContext::forWrite(OrganizationMembership::class);

        // Organization-wide, whichever property the caller is working in: the grant may name any of the organization's properties.
        return Tenancy::within(TenantContext::forOrganization($organization), fn () => DB::transaction(function () use ($member, $grant, $actor) {
            if (OrganizationMembership::query()->where('user_id', $member->getKey())->exists()) {
                throw MembershipRefused::alreadyAMember();
            }

            $membership = OrganizationMembership::query()->create([
                'user_id' => $member->getKey(),
                'all_properties' => $grant->allProperties,
            ]);

            if (! $grant->allProperties) {
                foreach ($grant->propertyIds as $propertyId) {
                    PropertyMembership::query()->create(['user_id' => $member->getKey(), 'property_id' => $propertyId]);
                }
            }

            $member->unsetRelation('roles')->assignRole($grant->role->value);

            Audit::record($actor, 'member_added', $membership, 'Member added', [
                'attributes' => [
                    'user_id' => $member->getKey(),
                    'role' => $grant->role->value,
                    'all_properties' => $grant->allProperties,
                    'property_ids' => $grant->allProperties ? [] : $grant->propertyIds,
                ],
            ]);

            return $membership;
        }));
    }
}
