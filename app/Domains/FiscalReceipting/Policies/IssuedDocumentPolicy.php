<?php

namespace App\Domains\FiscalReceipting\Policies;

use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;

class IssuedDocumentPolicy
{
    public function correct(User $user, IssuedDocument $document): bool
    {
        return Tenancy::current()?->propertyId === $document->property_id
            && Access::inProperty($user, OrganizationPermission::OutboxRecover, $document);
    }
}
