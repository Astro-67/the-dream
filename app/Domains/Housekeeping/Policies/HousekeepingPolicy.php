<?php

namespace App\Domains\Housekeeping\Policies;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class HousekeepingPolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::HousekeepingView, Tenancy::property());
    }

    public function view(User $user, RoomStatus $status): bool
    {
        return Access::allows($user, P::HousekeepingView, $status);
    }

    public function update(User $user, RoomStatus $status): bool
    {
        return $status->status === HousekeepingStatus::InspectionRequired
            ? Access::allows($user, P::HousekeepingSupervise, $status)
            : Access::allows($user, P::HousekeepingManage, $status);
    }

    public function overrideReady(User $user, RoomStatus $status): bool
    {
        return Access::allows($user, P::HousekeepingOverrideReady, $status);
    }

    public function supervise(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::HousekeepingSupervise, Tenancy::property());
    }
}
