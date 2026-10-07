<?php

namespace App\Http\Controllers\Web\Platform;

use App\Domains\Platform\Actions\AssignPlatformRole;
use App\Domains\Platform\Actions\ListPlatformStaff;
use App\Domains\Platform\Actions\RevokePlatformRole;
use App\Domains\Platform\Enums\PlatformRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StorePlatformRoleRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform staff and their platform roles. Only platform administrators reach
 * it (route middleware); the actions check the actor again.
 */
class StaffController extends Controller
{
    public function index(ListPlatformStaff $listPlatformStaff): Response
    {
        Gate::authorize('viewAny', User::class);

        return Inertia::render('platform/staff/index', [
            'staff' => $listPlatformStaff->handle(),
            'roles' => array_map(fn (PlatformRole $role) => ['value' => $role->value, 'label' => $role->label()], PlatformRole::cases()),
        ]);
    }

    public function store(StorePlatformRoleRequest $request, AssignPlatformRole $assign): RedirectResponse
    {
        Gate::authorize('managePlatformStaff', User::class);

        /** @var User $actor */
        $actor = $request->user();
        $grantee = $request->grantee();
        $role = $request->role();

        $assign->handle($grantee, $role, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is now a :role.', ['name' => $grantee->name, 'role' => strtolower($role->label())])]);

        return redirect()->route('platform.staff.index');
    }

    public function destroy(Request $request, User $user, PlatformRole $role, RevokePlatformRole $revoke): RedirectResponse
    {
        Gate::authorize('managePlatformStaff', User::class);

        /** @var User $actor */
        $actor = $request->user();

        $revoke->handle($user, $role, $actor);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is no longer a :role.', ['name' => $user->name, 'role' => strtolower($role->label())])]);

        return redirect()->route('platform.staff.index');
    }
}
