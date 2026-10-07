<?php

namespace App\Support\Tenancy;

/**
 * For the tenant model itself (Organization): queries only ever see the
 * organization in context, and fail closed without one. Creating a new
 * organization needs no context, since it is the tenant coming into existence.
 */
trait IsTenant
{
    public static function bootIsTenant(): void
    {
        static::addGlobalScope(new TenantScope('id'));
    }
}
