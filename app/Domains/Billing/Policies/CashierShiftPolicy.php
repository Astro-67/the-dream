<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

/** Whoever takes payments (charges.post) handles cash; finance reconciles the shifts. */
class CashierShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::FinanceView, Tenancy::property());
    }

    public function create(User $user): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ChargesPost, Tenancy::property());
    }

    /** Only the cashier counts and closes their own shift. */
    public function close(User $user, CashierShift $shift): bool
    {
        return $shift->user_id === $user->id && Access::allows($user, P::ChargesPost, $shift);
    }
}
