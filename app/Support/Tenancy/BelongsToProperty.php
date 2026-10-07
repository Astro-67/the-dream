<?php

namespace App\Support\Tenancy;

/**
 * For property-owned models (with organization_id and property_id): scoped to
 * the organization in context, and also to the property when one is in context.
 * Creating fills both IDs from the context.
 */
trait BelongsToProperty
{
    use BelongsToTenant;
}
