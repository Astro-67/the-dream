<?php

namespace App\Domains\Billing\Policies;

use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Tenancy\Enums\OrganizationPermission as P;
use App\Models\User;
use App\Support\Tenancy\Access;

class FeeProposalPolicy
{
    /** Post or waive the proposed fee. */
    public function resolve(User $user, FeeProposal $proposal): bool
    {
        return Access::inProperty($user, P::ChargesPost, $proposal);
    }
}
