<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Tenancy;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Creates the organization roles (PRD §5) in the current organization's spatie
 * team, each with the permission set OrganizationRole defines. Safe to re-run:
 * it brings existing roles up to date.
 */
class ProvisionOrganizationRoles
{
    public function handle(): void
    {
        $organization = Tenancy::organization() ?? throw MissingTenantContext::forWrite(Role::class);

        foreach (OrganizationRole::cases() as $definition) {
            $role = Role::query()->firstOrCreate([
                'organization_id' => $organization->getKey(),
                'name' => $definition->value,
                'guard_name' => 'web',
            ]);

            $role->syncPermissions(array_map(
                fn (string $name) => Permission::findOrCreate($name, 'web'),
                $definition->permissions(),
            ));
        }
    }
}
