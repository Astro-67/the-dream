<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;

/**
 * Laravel restores a queued job's models without global scopes. For tenant
 * models we keep the tenant scope, so a job (which runs inside its captured
 * tenant context) can't be handed a record of another tenant: it is simply not
 * found and the job fails. Used by BelongsToTenant and IsTenant.
 */
trait RestoresWithinTenant
{
    /**
     * @param  array<int, mixed>|int|string  $ids
     * @return Builder<static>
     */
    public function newQueryForRestoration($ids)
    {
        $query = $this->newQueryWithoutScopes()->whereKey($ids);

        static::getGlobalScope(TenantScope::class)?->apply($query, $this);

        return $query;
    }
}
