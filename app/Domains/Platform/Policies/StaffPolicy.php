<?php

namespace App\Domains\Platform\Policies;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;

/** Authorization for platform staff administration. */
final class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPlatformRole(PlatformRole::Administrator);
    }

    public function managePlatformStaff(User $user): bool
    {
        return $user->hasPlatformRole(PlatformRole::Administrator);
    }

    public function assignPlatformRole(User $user): bool
    {
        return $this->managePlatformStaff($user);
    }

    public function revokePlatformRole(User $user): bool
    {
        return $this->managePlatformStaff($user);
    }
}
