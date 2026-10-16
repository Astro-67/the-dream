<?php

namespace App\Domains\Reports\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Access;

class ReportPolicy
{
    public function view(User $user): bool
    {
        return Access::inProperty($user, OrganizationPermission::ReportsView);
    }

    /** Reports of one property in a group view, whichever property is current. */
    public function viewProperty(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::ReportsView, $property);
    }
}
