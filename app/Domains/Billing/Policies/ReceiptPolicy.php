<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Receipt;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class ReceiptPolicy
{
    public function fileAdvance(User $user, Receipt $receipt): bool
    {
        return Access::inProperty($user, P::ChargesPost, $receipt);
    }

    public function view(User $user, Receipt $receipt): bool
    {
        return Tenancy::property() !== null && (Access::allows($user, P::ReservationsView, $receipt) || Access::allows($user, P::FinanceView, $receipt) || Access::allows($user, P::ReportsView, $receipt));
    }
}
