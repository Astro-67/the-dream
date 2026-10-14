<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

/** Whoever takes payments (charges.post) handles cash; finance reconciles the shifts. */
class CashierShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return Access::inProperty($user, P::FinanceView);
    }

    public function create(User $user): bool
    {
        return Access::inProperty($user, P::ChargesPost);
    }

    /** Only the cashier counts and closes their own shift. */
    public function close(User $user, CashierShift $shift): bool
    {
        return $shift->user_id === $user->id && Access::allows($user, P::ChargesPost, $shift);
    }
}
