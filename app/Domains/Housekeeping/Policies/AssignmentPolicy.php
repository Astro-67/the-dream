<?php

namespace App\Domains\Housekeeping\Policies;

use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class AssignmentPolicy
{
    public function supervise(User $user): bool
    {
        return Access::inProperty($user, P::HousekeepingSupervise);
    }

    public function viewOwn(User $user): bool
    {
        return Access::inProperty($user, P::HousekeepingManage);
    }

    public function delete(User $user, Assignment $assignment): bool
    {
        return Access::allows($user, P::HousekeepingSupervise, $assignment);
    }

    public function complete(User $user, Assignment $assignment): bool
    {
        return $assignment->housekeeper_id === $user->id && Access::allows($user, P::HousekeepingManage, $assignment);
    }
}
