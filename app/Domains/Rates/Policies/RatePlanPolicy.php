<?php

namespace App\Domains\Rates\Policies;

use App\Domains\Rates\Models\RatePlan;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class RatePlanPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::ReservationsView, Tenancy::property());
    }

    public function view(User $user, RatePlan $plan): bool
    {
        return Access::allows($user, OrganizationPermission::ReservationsView, $plan);
    }

    public function create(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, OrganizationPermission::RatesManage, Tenancy::property());
    }

    public function update(User $user, RatePlan $plan): bool
    {
        return Access::allows($user, OrganizationPermission::RatesManage, $plan);
    }
}
