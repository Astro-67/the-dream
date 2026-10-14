<?php

namespace App\Domains\Subscriptions\Policies;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;

/** Only platform administrators issue SaaS invoices and record payments; owners see theirs on the subscription page. */
class SaasInvoicePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasPlatformRole(PlatformRole::Administrator);
    }
}
