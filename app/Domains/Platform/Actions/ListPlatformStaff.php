<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Models\PlatformRoleAssignment;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Everyone holding a platform role, with their roles and whether they have
 * two-factor authentication on (they need it before platform pages open).
 */
final class ListPlatformStaff
{
    /**
     * @return list<array{id: string, name: string, email: string, roles: list<string>, twoFactorEnabled: bool}>
     */
    public function handle(): array
    {
        return Tenancy::within(TenantContext::platform('Platform administrator listed platform staff'), fn () => User::query()
            ->whereHas('platformRoleAssignments')
            ->with('platformRoleAssignments')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->platformRoleAssignments
                    ->map(fn (PlatformRoleAssignment $assignment) => $assignment->role->value)
                    ->sort()->values()->all(),
                'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
            ])
            ->values()->all());
    }
}
