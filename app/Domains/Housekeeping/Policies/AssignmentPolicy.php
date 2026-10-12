<?php

namespace App\Domains\Housekeeping\Policies;

use App\Domains\Housekeeping\Models\Assignment;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class AssignmentPolicy
{
    public function supervise(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::HousekeepingSupervise, Tenancy::property());
    }

    public function viewOwn(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::HousekeepingManage, Tenancy::property());
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
