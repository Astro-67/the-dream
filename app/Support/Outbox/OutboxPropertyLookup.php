<?php

namespace App\Support\Outbox;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

final class OutboxPropertyLookup
{
    public function find(string $propertyId): ?Property
    {
        return Tenancy::within(
            TenantContext::platform('Resolve property scope for outbox recovery authorization'),
            fn () => Property::query()->find($propertyId),
        );
    }
}
