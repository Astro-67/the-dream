<?php

namespace App\Domains\FrontDesk\Policies;

use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class BusinessDateClosePolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::NightAuditView);
    }

    public function view(User $user, BusinessDateClose $close): bool
    {
        return Access::inProperty($user, P::NightAuditView, $close);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::NightAuditClose);
    }
}
