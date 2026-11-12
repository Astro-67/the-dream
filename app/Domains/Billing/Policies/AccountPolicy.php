<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::ChargesPost) || Access::inProperty($user, P::FinanceView);
    }

    public function view(User $user, CustomerAccount $account): bool
    {
        return Access::inProperty($user, P::ChargesPost, $account) || Access::inProperty($user, P::FinanceView, $account);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::ChargesPost);
    }

    public function close(User $user, CustomerAccount $account): bool
    {
        return Access::inProperty($user, P::ChargesPost, $account);
    }
}
