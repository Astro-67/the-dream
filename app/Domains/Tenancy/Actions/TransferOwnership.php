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
 * Hands ownership of the organization from an owner to another member: the only
 * way anyone becomes an owner. The target becomes an owner with all properties;
 * the previous owner takes the role and properties they chose (staying an owner
 * is one choice).
 */
class TransferOwnership
{
    use WritesMembershipGrants;

    /**
     * @param  MembershipGrant  $previousOwnerGrant  what the previous owner keeps
     *
     * @throws MembershipChangeRefused
     */
    public function handle(User $previousOwner, OrganizationMembership $target, MembershipGrant $previousOwnerGrant): void
    {
        Tenancy::within(TenantContext::forOrganization($target->organization_id), fn () => DB::transaction(function () use ($previousOwner, $target, $previousOwnerGrant) {
            $this->lockMemberships();

            $source = OrganizationMembership::query()->where('user_id', $previousOwner->getKey())->first();

            if ($source === null || $source->grant()->role !== OrganizationRole::Owner) {
                throw MembershipChangeRefused::notAnOwner();
            }

            if ($source->is($target)) {
                throw MembershipChangeRefused::transferToYourself();
            }

            $targetBefore = $target->grant();
            $sourceBefore = $source->grant();

            if ($targetBefore->role === OrganizationRole::Owner) {
                throw MembershipChangeRefused::alreadyAnOwner($target->user->name);
            }

            $targetAfter = MembershipGrant::allProperties(OrganizationRole::Owner);

            // The new owner first, so the organization is never without one.
            $this->writeGrant($target, $targetAfter);
            $this->writeGrant($source, $previousOwnerGrant);

            Audit::record($previousOwner, 'ownership_transferred', $target, 'Ownership transferred', [
                'old' => [
                    'new_owner' => ['user_id' => $target->user_id, ...$targetBefore->toAuditValues()],
                    'previous_owner' => ['user_id' => $source->user_id, ...$sourceBefore->toAuditValues()],
                ],
                'attributes' => [
                    'new_owner' => ['user_id' => $target->user_id, ...$targetAfter->toAuditValues()],
                    'previous_owner' => ['user_id' => $source->user_id, ...$previousOwnerGrant->toAuditValues()],
                ],
            ]);
        }));
    }
}
