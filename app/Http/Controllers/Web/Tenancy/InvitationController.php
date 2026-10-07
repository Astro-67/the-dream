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
 * Inviting people to the current organization, and the pending invitations (PRD ORG-02).
 */
class InvitationController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Invitation::class);

        /** @var User $user */
        $user = $request->user();
        $propertyNames = Property::query()->pluck('name', 'id');

        $invitations = Invitation::query()
            ->pending()
            ->with('inviter')
            ->latest()
            ->get()
            ->filter(fn (Invitation $invitation) => $user->can('view', $invitation))
            ->map(fn (Invitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role->value,
                'roleLabel' => $invitation->role->label(),
                'allProperties' => $invitation->all_properties,
                'properties' => collect($invitation->property_ids)->map(fn (string $id) => $propertyNames[$id] ?? null)->filter()->values()->all(),
                'invitedBy' => $invitation->inviter?->name,
                'expiresAt' => $invitation->expires_at->toIso8601String(),
                'canRevoke' => $user->can('revoke', $invitation),
            ])
            ->values();

        return Inertia::render('invitations/index', [
            'invitations' => $invitations,
            'canInvite' => $user->can('create', Invitation::class),
        ]);
    }

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
            'properties' => $properties->map(fn (Property $p) => ['value' => $p->id, 'label' => $p->name])->values(),
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

        return redirect()->route('invitations.index');
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

        return redirect()->route('invitations.index');
    }
}
