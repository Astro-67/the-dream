<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Platform\Models\PlatformRoleAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Takes a platform role away. Like assigning, only a platform administrator or
 * a seed or console command (no $revokedBy) may do it.
 */
final class RevokePlatformRole
{
    public function handle(User $user, PlatformRole $role, ?User $revokedBy = null): void
    {
        if ($revokedBy !== null) {
            Gate::forUser($revokedBy)->authorize('revokePlatformRole', User::class);
        }

        // So a platform administrator can't lock themselves out; another administrator or a console command can.
        if ($role === PlatformRole::Administrator && $revokedBy?->is($user)) {
            throw ValidationException::withMessages(['role' => __('You cannot remove your own platform administrator role.')]);
        }

        DB::transaction(function () use ($user, $role, $revokedBy) {
            $deleted = PlatformRoleAssignment::query()
                ->where('user_id', $user->id)
                ->where('role', $role->value)
                ->delete();

            if ($deleted === 0) {
                return;
            }

            activity('platform')
                ->causedBy($revokedBy)
                ->performedOn($user)
                ->event('platform_role_revoked')
                ->withProperties(['role' => $role->value])
                ->log('Revoked a platform role');
        });
    }
}
