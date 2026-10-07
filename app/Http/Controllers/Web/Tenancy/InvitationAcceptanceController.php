<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\AcceptInvitation;
use App\Domains\Tenancy\Actions\InvitationAcceptance;
use App\Domains\Tenancy\Exceptions\InvitationUnavailable;
use App\Domains\Tenancy\Exceptions\MembershipRefused;
use App\Domains\Tenancy\Models\Invitation;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The emailed invitation link (PRD ORG-02). The link's token is what lets the
 * invitee see the invitation; accepting also needs the invited email address.
 */
class InvitationAcceptanceController extends Controller
{
    public function show(Request $request, string $organization, string $token): Response
    {
        $invitation = Invitation::findByLink($organization, $token) ?? abort(404);
        $user = $request->user();

        if (! $user instanceof User) {
            // Come back here after signing in or registering.
            $request->session()->put('url.intended', $request->url());
        }

        return Inertia::render('auth/invitation', [
            'invitation' => Tenancy::within(TenantContext::forOrganization($invitation->organization_id), fn () => [
                'organization' => $invitation->organization->name,
                'role' => $invitation->role->label(),
                'email' => $invitation->email,
                'invitedBy' => $invitation->inviter?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
            ]),
            'refusal' => InvitationAcceptance::refusal($invitation, $user),
            'signedInAs' => $user?->email,
            'link' => ['organization' => $organization, 'token' => $token],
        ]);
    }

    public function store(Request $request, string $organization, string $token, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $invitation = Invitation::findByLink($organization, $token) ?? abort(404);

        /** @var User $user */
        $user = $request->user();

        try {
            $acceptInvitation->handle($user, $invitation);
        } catch (InvitationUnavailable) {
            return redirect()->route('invitations.show', ['organization' => $organization, 'token' => $token]);
        } catch (MembershipRefused $refused) {
            abort(403, $refused->getMessage());
        }

        // Land in the organization just joined, on the first property the member may act in.
        $context = Tenancy::within(TenantContext::forOrganization($invitation->organization_id), function () use ($user, $invitation) {
            $property = Tenancy::propertiesFor($user)->first();

            return $property === null ? TenantContext::forOrganization($invitation->organization_id) : TenantContext::forProperty($property);
        });
        Tenancy::remember($request->session(), $context);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation accepted. Welcome aboard.')]);

        return redirect()->route('dashboard');
    }
}
