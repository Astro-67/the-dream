<?php

namespace App\Domains\FiscalReceipting\Policies;

use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class DocumentCorrectionPolicy
{
    public function refresh(User $user, DocumentCorrection $correction): bool
    {
        return Tenancy::current()?->propertyId === $correction->property_id
            && Access::inProperty($user, OrganizationPermission::OutboxRecover, $correction);
    }
}
