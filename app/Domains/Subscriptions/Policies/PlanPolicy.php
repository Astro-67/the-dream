<?php

namespace App\Domains\Subscriptions\Policies;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;

/** Plans are platform data: only platform administrators see or change them. */
class PlanPolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPlatformRole(PlatformRole::Administrator);
    }
}
