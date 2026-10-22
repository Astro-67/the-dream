<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\RevokeInvitation;
use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Exceptions\InvitationUnavailable;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\StoreInvitationRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inviting people to the current organization (PRD ORG-02). Pending invitations are listed with the staff.
 */
class InvitationController extends Controller
{
    public function create(Request $request): Response
    {
        Gate::authorize('create', Invitation::class);

        /** @var User $user */
        $user = $request->user();
        $managesMembers = Access::allows($user, OrganizationPermission::MembersManage);

        $roles = array_values(array_filter(
            OrganizationRole::cases(),
            fn (OrganizationRole $role) => $managesMembers || $role->isPropertyStaff(),
        ));
        $properties = $managesMembers
            ? Property::query()->active()->orderBy('name')->get()
            : Tenancy::propertiesFor($user);

        return Inertia::render('invitations/create', [
            'roles' => array_map(fn (OrganizationRole $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'mayHaveAllProperties' => $managesMembers && $role->mayHaveAllProperties(),
            ], $roles),
            'properties' => $properties->map(fn (Property $p) => ['value' => $p->id, 'label' => $p->displayName()])->values(),
            'defaultExpiryDays' => InvitationDetails::DEFAULT_EXPIRY_DAYS,
        ]);
    }

    public function store(StoreInvitationRequest $request, SendInvitation $sendInvitation): RedirectResponse
    {
        $details = $request->details();
        Gate::authorize('create', [Invitation::class, $details->grant]);

        /** @var User $user */
        $user = $request->user();
        $sendInvitation->handle($user, $details);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation sent to :email.', ['email' => $details->email])]);

        return redirect()->route('members.index');
    }

    public function destroy(Request $request, Invitation $invitation, RevokeInvitation $revokeInvitation): RedirectResponse
    {
        Gate::authorize('revoke', $invitation);

        /** @var User $user */
        $user = $request->user();

        try {
            $revokeInvitation->handle($user, $invitation);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Invitation for :email revoked.', ['email' => $invitation->email])]);
        } catch (InvitationUnavailable) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('The invitation for :email is no longer pending.', ['email' => $invitation->email])]);
        }

        return redirect()->route('members.index');
    }
}
