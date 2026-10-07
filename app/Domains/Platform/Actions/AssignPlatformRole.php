<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Platform\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Gives a user a platform role. The only way one is granted: by another
 * platform administrator, or by a seed or console command (no $assignedBy).
 * Organization membership and invitations never reach this.
 */
final class AssignPlatformRole
{
    /**
     * @throws AuthorizationException when $assignedBy is not a platform administrator
     */
    public function handle(User $user, PlatformRole $role, ?User $assignedBy = null): void
    {
        if ($assignedBy !== null && ! $assignedBy->hasPlatformRole(PlatformRole::Administrator)) {
            throw new AuthorizationException('Only a platform administrator can assign platform roles.');
        }

        if ($user->hasPlatformRole($role)) {
            return;
        }

        PlatformRoleAssignment::query()->create([
            'user_id' => $user->id,
            'role' => $role,
            'assigned_by' => $assignedBy?->id,
        ]);

        activity('platform')
            ->causedBy($assignedBy)
            ->performedOn($user)
            ->event('platform_role_assigned')
            ->withProperties(['role' => $role->value])
            ->log('Assigned a platform role');
    }
}
