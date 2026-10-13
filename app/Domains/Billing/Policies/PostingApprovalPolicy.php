<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class PostingApprovalPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::ApprovalsManage);
    }

    public function resolve(User $user, PostingApproval $approval): bool
    {
        return Access::inProperty($user, P::ApprovalsManage, $approval);
    }

    public function configure(User $user): bool
    {
        return $this->viewAny($user);
    }
}
