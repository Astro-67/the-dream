<?php

namespace App\Domains\Reservations\Policies;

use App\Domains\Reservations\Models\NoShowProposal;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class NoShowProposalPolicy
{
    /** Confirm or reject the proposed no-show: night audit managers only. */
    public function resolve(User $user, NoShowProposal $proposal): bool
    {
        return Tenancy::property() !== null && Access::allows($user, P::NightAuditClose, $proposal);
    }
}
