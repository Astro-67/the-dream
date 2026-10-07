<?php

namespace App\Console\Commands;

use App\Domains\Tenancy\Actions\ProvisionOrganizationRoles;
use App\Domains\Tenancy\Models\Organization;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Re-seeds every organization's roles from OrganizationRole::permissions(). Run it
 * after a deploy that changes a role's permission set, so existing organizations
 * match new ones. Safe to re-run.
 */
#[Signature('organizations:sync-roles')]
#[Description('Bring every organization\'s roles up to date with their defined permission sets')]
class SyncOrganizationRoles extends Command
{
    public function handle(ProvisionOrganizationRoles $provisionRoles): int
    {
        $organizationIds = Tenancy::within(
            TenantContext::platform('Sync organization roles with their defined permission sets'),
            fn () => Organization::query()->orderBy('id')->pluck('id')->all(),
        );

        foreach ($organizationIds as $organizationId) {
            Tenancy::within(TenantContext::forOrganization($organizationId), fn () => $provisionRoles->handle());
        }

        $this->components->info('Synced the roles of '.count($organizationIds).' organizations.');

        return self::SUCCESS;
    }
}
