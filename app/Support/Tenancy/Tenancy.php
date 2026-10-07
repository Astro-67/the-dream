<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * Entry point of the Tenancy module (ADR 0012). See TenancyManager.
 *
 * @method static mixed within(TenantContext $context, Closure $callback)
 * @method static TenantContext|null current()
 * @method static Organization|null organization()
 * @method static Property|null property()
 * @method static bool isPlatform()
 * @method static bool allows(User $user, TenantContext $context)
 * @method static TenantContext|null defaultContextFor(User $user, ?string $organizationId = null)
 * @method static bool isArchivedProperty(string $organizationId, string $propertyId)
 * @method static bool allowsArchived(User $user, TenantContext $context)
 * @method static bool hasMembership(User $user)
 * @method static Collection<int, Organization> organizationsFor(User $user)
 * @method static Collection<int, Property> propertiesFor(User $user)
 *
 * @see TenancyManager
 */
final class Tenancy extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TenancyManager::class;
    }
}
