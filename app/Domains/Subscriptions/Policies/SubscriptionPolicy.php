<?php

namespace App\Domains\Subscriptions\Policies;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;

/** The organization's own subscription: its owners see it (organization.manage). */
class SubscriptionPolicy
{
    public function view(User $user): bool
    {
        return Access::allows($user, OrganizationPermission::OrganizationManage);
    }
}
