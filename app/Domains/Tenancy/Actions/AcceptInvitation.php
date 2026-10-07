<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\InvitationStatus;
use App\Domains\Tenancy\Exceptions\InvitationUnavailable;
use App\Domains\Tenancy\Exceptions\MembershipRefused;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Accepts an invitation for a signed-in user whose email is the invited one:
 * they become a member of the inviting organization with the invited grant.
 * An existing account keeps its identity and gains the membership.
 *
 * Runs in the inviting organization's context, which the invitee isn't in yet.
 */
class AcceptInvitation
{
    public function __construct(private readonly AddOrganizationMember $addMember) {}

    /**
     * @throws InvitationUnavailable when the invitation was used, revoked or has expired
     * @throws MembershipRefused when the user's email isn't the invited one, or they're already a member
     */
    public function handle(User $user, Invitation $invitation): OrganizationMembership
    {
        return Tenancy::within(TenantContext::forOrganization($invitation->organization_id), fn () => DB::transaction(function () use ($user, $invitation) {
            /** @var Invitation $invitation */
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($invitation->status() !== InvitationStatus::Pending) {
                throw new InvitationUnavailable($invitation->status());
            }

            if (! $invitation->isFor($user)) {
                throw MembershipRefused::wrongEmail();
            }

            $grant = $invitation->all_properties
                ? MembershipGrant::allProperties($invitation->role)
                : MembershipGrant::properties($invitation->role, $invitation->property_ids);

            $membership = $this->addMember->handle($user, $grant, $user);

            $invitation->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);

            // The link reached this address's inbox, which is what verifying the email proves.
            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            Audit::record($user, 'accepted', $invitation, 'Invitation accepted', [
                'attributes' => ['email' => $invitation->email, 'role' => $invitation->role->value],
            ]);

            return $membership;
        }));
    }
}
