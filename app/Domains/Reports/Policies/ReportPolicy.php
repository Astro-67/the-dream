<?php

namespace App\Domains\Reports\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;

class ReportPolicy
{
    public function view(User $user): bool
    {
        return Access::inProperty($user, OrganizationPermission::ReportsView);
    }
}
