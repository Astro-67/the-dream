<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Actions\Concerns\WritesMembershipGrants;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Exceptions\MembershipChangeRefused;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Changes a member's role and properties. It applies on their next request,
 * since every request rechecks membership (Access, SetTenantContext).
 *
 * Refused when it would demote the organization's last owner, or make someone an
 * owner: ownership moves only through TransferOwnership.
 */
class ChangeMembership
{
    use WritesMembershipGrants;

    /**
     * @throws MembershipChangeRefused
     */
    public function handle(?User $actor, OrganizationMembership $membership, MembershipGrant $grant): OrganizationMembership
    {
        try {
            return Tenancy::within(TenantContext::forOrganization($membership->organization_id), fn () => DB::transaction(function () use ($actor, $membership, $grant) {
                $this->lockMemberships();
                $before = $membership->grant();

                if ($grant->role === OrganizationRole::Owner && $before->role !== OrganizationRole::Owner) {
                    throw MembershipChangeRefused::ownershipOnlyByTransfer();
                }

                if ($before->role === OrganizationRole::Owner && $grant->role !== OrganizationRole::Owner && $this->ownerCount() <= 1) {
                    throw MembershipChangeRefused::lastOwner();
                }

                if ($before->toAuditValues() === $grant->toAuditValues()) {
                    return $membership;
                }

                $this->writeGrant($membership, $grant);

                Audit::record($actor, 'member_updated', $membership, 'Member access changed', [
                    'old' => ['user_id' => $membership->user_id, ...$before->toAuditValues()],
                    'attributes' => ['user_id' => $membership->user_id, ...$grant->toAuditValues()],
                ]);

                return $membership;
            }));
        } catch (MembershipChangeRefused $refused) {
            if ($refused->getMessage() === MembershipChangeRefused::lastOwner()->getMessage()) {
                Tenancy::within(TenantContext::forOrganization($membership->organization_id), fn () => Audit::record($actor, 'member_change_refused', $membership, 'Member role change refused', [
                    'result' => 'refused', 'reason' => $refused->getMessage(),
                    'old' => ['user_id' => $membership->user_id, ...$membership->grant()->toAuditValues()],
                ]));
            }

            throw $refused;
        }
    }
}
