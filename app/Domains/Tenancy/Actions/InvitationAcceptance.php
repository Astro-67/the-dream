<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Exceptions\MembershipRefused;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/** Read-side eligibility and destination decisions for invitation links. */
final class InvitationAcceptance
{
    public static function refusal(Invitation $invitation, ?User $user): ?string
    {
        if ($invitation->status()->refusal() !== null) {
            return $invitation->status()->refusal();
        }

        if ($user === null) {
            return null;
        }

        if (! $invitation->isFor($user)) {
            return MembershipRefused::wrongEmail()->getMessage();
        }

        $isMember = Tenancy::within(
            TenantContext::forOrganization($invitation->organization_id),
            fn () => OrganizationMembership::query()->where('user_id', $user->getKey())->exists(),
        );

        return $isMember ? MembershipRefused::alreadyAMember()->getMessage() : null;
    }
}
