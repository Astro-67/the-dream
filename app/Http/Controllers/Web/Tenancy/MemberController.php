<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\ChangeMembership;
use App\Domains\Tenancy\Actions\RemoveMember;
use App\Domains\Tenancy\Actions\TransferOwnership;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Exceptions\MembershipChangeRefused;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\TransferOwnershipRequest;
use App\Http\Requests\Tenancy\UpdateMembershipRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The people in the current organization, with their roles and properties (PRD ORG-02).
 */
class MemberController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', OrganizationMembership::class);

        /** @var User $user */
        $user = $request->user();
        $propertyNames = Property::query()->orderBy('name')->get()
            ->mapWithKeys(fn (Property $property) => [$property->id => $property->displayName()]);

        $members = OrganizationMembership::query()
            ->with('user')
            ->get()
            ->filter(fn (OrganizationMembership $membership) => $user->can('view', $membership))
            ->map(function (OrganizationMembership $membership) use ($user, $propertyNames) {
                $grant = $membership->grant();

                return [
                    'id' => $membership->id,
                    'name' => $membership->user->name,
                    'email' => $membership->user->email,
                    'role' => $grant->role->value,
                    'roleLabel' => $grant->role->label(),
                    'allProperties' => $grant->allProperties,
                    'properties' => $propertyNames->only($grant->propertyIds)->values()->all(),
                    'isYou' => $membership->user_id === $user->getKey(),
                    'canManage' => $user->can('update', $membership),
                ];
            })
            ->sortBy([['name', 'asc'], ['email', 'asc']])
            ->values();

        return Inertia::render('members/index', [
            'members' => $members,
        ]);
    }

    public function edit(Request $request, OrganizationMembership $membership): Response
    {
        Gate::authorize('update', $membership);

        /** @var User $user */
        $user = $request->user();
        $grant = $membership->grant();
        $isOwner = $grant->role === OrganizationRole::Owner;
        $roleOption = fn (OrganizationRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'mayHaveAllProperties' => $role->mayHaveAllProperties(),
        ];

        return Inertia::render('members/edit', [
            'member' => [
                'id' => $membership->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'role' => $grant->role->value,
                'roleLabel' => $grant->role->label(),
                'allProperties' => $grant->allProperties,
                'propertyIds' => $grant->propertyIds,
                'isYou' => $membership->user_id === $user->getKey(),
                'canTransferOwnership' => ! $isOwner && $user->can('transferOwnership', $membership),
            ],
            // Owners are made only by a transfer: an owner may keep the role, no one else may be given it.
            'roles' => array_map($roleOption, array_values(array_filter(
                OrganizationRole::cases(),
                fn (OrganizationRole $role) => $isOwner || $role !== OrganizationRole::Owner,
            ))),
            // What the previous owner may keep after a transfer, staying an owner included.
            'transferRoles' => array_map($roleOption, OrganizationRole::cases()),
            'properties' => Property::query()->active()->orderBy('name')->get()
                ->map(fn (Property $property) => ['value' => $property->id, 'label' => $property->displayName()])->values(),
        ]);
    }

    public function update(UpdateMembershipRequest $request, OrganizationMembership $membership, ChangeMembership $changeMembership): RedirectResponse
    {
        Gate::authorize('update', $membership);

        /** @var User $user */
        $user = $request->user();

        try {
            $changeMembership->handle($user, $membership, $request->grant());
        } catch (MembershipChangeRefused $refused) {
            return back()->withErrors(['role' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Access for :name saved.', ['name' => $membership->user->name])]);

        return redirect()->route('members.index');
    }

    public function destroy(Request $request, OrganizationMembership $membership, RemoveMember $removeMember): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        /** @var User $user */
        $user = $request->user();

        try {
            $removeMember->handle($user, $membership);
        } catch (MembershipChangeRefused $refused) {
            return back()->withErrors(['member' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the organization.', ['name' => $membership->user->name])]);

        return redirect()->route('members.index');
    }

    public function transferOwnership(TransferOwnershipRequest $request, OrganizationMembership $membership, TransferOwnership $transferOwnership): RedirectResponse
    {
        Gate::authorize('transferOwnership', $membership);

        /** @var User $user */
        $user = $request->user();

        try {
            $transferOwnership->handle($user, $membership, $request->grant());
        } catch (MembershipChangeRefused $refused) {
            return back()->withErrors(['member' => $refused->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is now an owner.', ['name' => $membership->user->name])]);

        return redirect()->route('members.index');
    }
}
