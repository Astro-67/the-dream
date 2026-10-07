<?php

namespace App\Support\Tenancy;

use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits tenant-owned queries to the current tenant context, and fails closed
 * when there is none. Internal to the Tenancy module: nothing outside
 * App\Support\Tenancy may reference it (enforced by an architecture test).
 *
 * @internal
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
{
    public function __construct(
        private readonly string $organizationColumn,
        private readonly ?string $propertyColumn = null,
    ) {}

    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenancyManager::class)->current();

        if ($context === null) {
            throw MissingTenantContext::forQuery($model::class);
        }

        if ($context->isPlatform()) {
            return;
        }

        $builder->where($model->qualifyColumn($this->organizationColumn), $context->organizationId);

        if ($this->propertyColumn !== null && $context->propertyId !== null) {
            $builder->where($model->qualifyColumn($this->propertyColumn), $context->propertyId);
        }
    }
}
