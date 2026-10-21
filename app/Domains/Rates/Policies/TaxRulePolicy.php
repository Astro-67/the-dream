<?php

namespace App\Domains\Rates\Policies;

use App\Domains\Rates\Models\TaxRule;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class TaxRulePolicy
{
    public function viewAny(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::ReservationsView, Tenancy::property());
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, OrganizationPermission::TaxManage);
    }

    /** Tax inclusion is changed from the property form, outside a property context. */
    public function manageInclusion(User $user, Property $property): bool
    {
        return Access::allows($user, OrganizationPermission::TaxManage, $property);
    }

    public function update(User $user, TaxRule $rule): bool
    {
        return Access::allows($user, OrganizationPermission::TaxManage, $rule);
    }
}
