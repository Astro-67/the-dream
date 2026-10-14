<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * The authorization check every policy uses (ADR 0007): the member's role in the
 * current organization grants the permission, AND the record's property is one
 * the member may act in there. Both are rechecked on every call, so a removed
 * member or a withdrawn property is refused on the very next request.
 *
 *     Access::allows($user, OrganizationPermission::ReservationsManage, $reservation)
 *     Access::allows($user, OrganizationPermission::PropertiesUpdate, $property)
 *     Access::allows($user, OrganizationPermission::MembersManage)
 *
 * Always judged in the current tenant context's organization: a property of another
 * organization is never allowed, whatever the member's role there. Outside a tenant
 * (no context, or the platform scope) nothing is allowed.
 */
final class Access
{
    /**
     * @param  Property|Model|string|null  $property  the property, a record with a property_id, a property ID,
     *                                                or null for an action that belongs to no single property
     */
    public static function allows(User $user, OrganizationPermission $permission, Property|Model|string|null $property = null): bool
    {
        $organizationId = Tenancy::current()?->organizationId;

        if ($organizationId === null) {
            return false;
        }

        try {
            $hasPermission = $user->checkPermissionTo($permission->value);
        } catch (PermissionDoesNotExist) {
            return false;
        }

        if (! $hasPermission) {
            return false;
        }

        $propertyId = self::propertyId($property);

        $context = TenantContext::fromIds($organizationId, $propertyId);

        if ($permission->isReadOnly()) {
            return Tenancy::allows($user, $context) || Tenancy::allowsArchived($user, $context);
        }

        return ($propertyId === null || ! Tenancy::isArchivedProperty($organizationId, $propertyId))
            && Tenancy::allows($user, $context);
    }

    /**
     * allows() for property-scoped work: refuses outside a property context, and
     * checks the record's property, or the property in context when none is given.
     */
    public static function inProperty(User $user, OrganizationPermission $permission, ?Model $record = null): bool
    {
        $property = Tenancy::property();

        return $property !== null && self::allows($user, $permission, $record ?? $property);
    }

    /**
     * Whether the user is an owner of the current organization. Only for rules
     * about owners as people (they must use two-factor authentication); what a
     * member may do is always a permission, checked with allows().
     */
    public static function isOwner(User $user): bool
    {
        $organizationId = Tenancy::current()?->organizationId;

        return $organizationId !== null
            && Tenancy::allows($user, TenantContext::fromIds($organizationId))
            && $user->hasRole(OrganizationRole::Owner->value);
    }

    /**
     * The permissions the member may use in a property of the current organization
     * (or organization-wide, for null), for the interface to hide what it can't do.
     *
     * @return list<string>
     */
    public static function permissionsFor(User $user, ?Property $property = null): array
    {
        $organizationId = Tenancy::current()?->organizationId;

        if ($organizationId === null || ! Tenancy::allows($user, TenantContext::fromIds($organizationId, $property?->id))) {
            return [];
        }

        return $user->getAllPermissions()->pluck('name')->map(fn ($name) => (string) $name)->sort()->values()->all();
    }

    private static function propertyId(Property|Model|string|null $property): ?string
    {
        return match (true) {
            $property === null, is_string($property) => $property,
            $property instanceof Property => $property->id,
            // A record without a property (organization-owned) is checked organization-wide.
            default => is_string($property->getAttribute('property_id')) ? $property->getAttribute('property_id') : null,
        };
    }
}
