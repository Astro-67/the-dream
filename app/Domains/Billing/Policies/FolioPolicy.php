<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\Folio;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class FolioPolicy
{
    public function postCharge(User $user, Folio $folio): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::ChargesPost, $folio);
    }
}
