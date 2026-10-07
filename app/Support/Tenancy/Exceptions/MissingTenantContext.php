<?php

namespace App\Support\Tenancy\Exceptions;

use RuntimeException;

/**
 * Tenant-owned data was touched outside Tenancy::within(). We fail closed rather
 * than run the query unscoped.
 */
final class MissingTenantContext extends RuntimeException
{
    public static function forQuery(string $model): self
    {
        return new self("Querying [{$model}] requires a tenant context. Wrap the code in Tenancy::within().");
    }

    public static function forWrite(string $model): self
    {
        return new self("Creating [{$model}] requires a tenant context. Wrap the code in Tenancy::within().");
    }

    public static function forProperty(string $model): self
    {
        return new self("Creating [{$model}] requires a property in the tenant context, or an explicit property_id.");
    }

    public static function forJob(string $job): self
    {
        return new self("Tenant-scoped job [{$job}] was dispatched without a tenant context, so it will not run. Dispatch it inside Tenancy::within() with an organization (the platform context is not a tenant).");
    }

    public static function forPlatformWrite(string $model): self
    {
        return new self("Creating [{$model}] in the platform context requires an explicit organization_id (and property_id for property-owned records).");
    }
}
