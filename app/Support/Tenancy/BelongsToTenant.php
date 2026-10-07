<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Exceptions\TenantMismatch;
use Illuminate\Database\Eloquent\Model;

/**
 * For organization-owned models (with an organization_id column): queries are
 * limited to the organization in context and fail closed without one. Creating
 * fills organization_id from the context and refuses any other organization.
 *
 * Property-owned models use BelongsToProperty, which builds on this trait.
 */
trait BelongsToTenant
{
    use RestoresWithinTenant;

    public static function bootBelongsToTenant(): void
    {
        $propertyOwned = in_array(BelongsToProperty::class, class_uses_recursive(static::class), true);

        static::addGlobalScope(new TenantScope('organization_id', $propertyOwned ? 'property_id' : null));

        static::creating(function (Model $model) use ($propertyOwned): void {
            $context = app(TenancyManager::class)->current();

            if ($context === null) {
                throw MissingTenantContext::forWrite($model::class);
            }

            if ($context->isPlatform()) {
                if (blank($model->getAttribute('organization_id')) || ($propertyOwned && blank($model->getAttribute('property_id')))) {
                    throw MissingTenantContext::forPlatformWrite($model::class);
                }

                return;
            }

            self::fillTenantColumn($model, 'organization_id', $context->organizationId);

            if (! $propertyOwned) {
                return;
            }

            $propertyId = $model->getAttribute('property_id');

            if (blank($propertyId)) {
                if ($context->propertyId === null) {
                    throw MissingTenantContext::forProperty($model::class);
                }

                $model->setAttribute('property_id', $context->propertyId);

                return;
            }

            if ($context->propertyId !== null) {
                if ($propertyId !== $context->propertyId) {
                    throw TenantMismatch::forWrite($model::class, 'property_id');
                }

                return;
            }

            // No property in context: the named property must belong to the organization in context.
            if (! Property::query()->whereKey($propertyId)->exists()) {
                throw TenantMismatch::forWrite($model::class, 'property_id');
            }
        });

        static::updating(function (Model $model) use ($propertyOwned): void {
            foreach ($propertyOwned ? ['organization_id', 'property_id'] : ['organization_id'] as $column) {
                if ($model->isDirty($column)) {
                    throw TenantMismatch::reassigned($model::class, $column);
                }
            }
        });
    }

    private static function fillTenantColumn(Model $model, string $column, ?string $contextId): void
    {
        $current = $model->getAttribute($column);

        if (blank($current)) {
            $model->setAttribute($column, $contextId);
        } elseif ($current !== $contextId) {
            throw TenantMismatch::forWrite($model::class, $column);
        }
    }
}
