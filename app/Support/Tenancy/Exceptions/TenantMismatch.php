<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Code tried to write a record for a tenant other than the one in context, or
 * entered a context whose organization and property don't belong together.
 */
final class TenantMismatch extends RuntimeException
{
    public static function unknownOrganization(string $organizationId): self
    {
        return new self("Organization [{$organizationId}] does not exist.");
    }

    public static function propertyOutsideOrganization(string $propertyId, string $organizationId): self
    {
        return new self("Property [{$propertyId}] does not belong to organization [{$organizationId}].");
    }

    public static function forWrite(string $model, string $column): self
    {
        return new self("Refusing to write [{$model}] with a {$column} other than the current tenant context's.");
    }

    public static function reassigned(string $model, string $column): self
    {
        return new self("Refusing to move [{$model}] to another tenant by changing {$column}.");
    }
}
