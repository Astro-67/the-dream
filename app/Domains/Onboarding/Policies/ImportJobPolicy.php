<?php

namespace App\Domains\Onboarding\Policies;

use App\Domains\Onboarding\Models\ImportJob;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;

class ImportJobPolicy
{
    public function create(User $user): bool
    {
        return Access::inProperty($user, OrganizationPermission::PropertiesUpdate);
    }

    public function view(User $user, ImportJob $job): bool
    {
        return Access::inProperty($user, OrganizationPermission::PropertiesUpdate, $job);
    }
}
