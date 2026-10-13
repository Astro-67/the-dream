<?php

namespace App\Domains\FrontDesk\Policies;

use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class BusinessDateClosePolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::NightAuditView, Tenancy::property());
    }

    public function view(User $user, BusinessDateClose $close): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::NightAuditView, $close);
    }

    public function create(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::NightAuditClose, Tenancy::property());
    }
}
