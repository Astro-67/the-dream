<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Actions\Concerns\WritesMembershipGrants;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Exceptions\MembershipChangeRefused;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Removes a member from the organization: their membership, property memberships
 * and role. They are refused on their next request, since every request rechecks
 * membership. Refused for the organization's last owner.
 */
class RemoveMember
{
    use WritesMembershipGrants;

    /**
     * @throws MembershipChangeRefused
     */
    public function handle(?User $actor, OrganizationMembership $membership): void
    {
        Tenancy::within(TenantContext::forOrganization($membership->organization_id), fn () => DB::transaction(function () use ($actor, $membership) {
            $this->lockMemberships();
            $before = $membership->grant();

            if ($before->role === OrganizationRole::Owner && $this->ownerCount() <= 1) {
                throw MembershipChangeRefused::lastOwner();
            }

            /** @var User $member */
            $member = $membership->user;

            PropertyMembership::query()->where('user_id', $member->getKey())->delete();
            $member->unsetRelation('roles')->syncRoles([]);
            $member->unsetRelation('permissions')->syncPermissions([]);
            $membership->delete();

            Audit::record($actor, 'member_removed', $membership, 'Member removed', [
                'old' => ['user_id' => $member->getKey(), ...$before->toAuditValues()],
            ]);
        }));
    }
}
