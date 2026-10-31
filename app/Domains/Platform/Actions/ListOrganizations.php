<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * The minimal organization list platform administrators use for provisioning
 * and support: name, owners, number of active properties and created date.
 * Nothing from inside the organizations (guests, reservations, folios, files).
 *
 * Reads across organizations, so it runs in the platform context and every
 * call is logged with the reason.
 */
final class ListOrganizations
{
    /**
     * @return list<array{id: string, name: string, owners: list<array{name: string, email: string}>, propertyCount: int, createdAt: ?string}>
     */
    public function handle(): array
    {
        return Tenancy::within(TenantContext::platform('Platform administrator listed organizations'), function (): array {
            $organizations = Organization::query()
                ->withCount(['properties' => fn ($query) => $query->active()])
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            $owners = $this->ownersOf($organizations->modelKeys());

            return $organizations->map(fn (Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'owners' => $owners[$organization->id] ?? [],
                'propertyCount' => (int) $organization->getAttribute('properties_count'),
                'createdAt' => $organization->created_at?->toIso8601String(),
            ])->values()->all();
        });
    }

    /**
     * Holders of the owner role in each organization's spatie team.
     *
     * @param  array<int, mixed>  $organizationIds
     * @return array<string, list<array{name: string, email: string}>>
     */
    public function ownersOf(array $organizationIds): array
    {
        /** @var array{model_has_roles: string, roles: string} $tables */
        $tables = config('permission.table_names');
        /** @var string $teamKey */
        $teamKey = config('permission.column_names.team_foreign_key');

        $rows = DB::table($tables['model_has_roles'].' as assigned')
            ->join($tables['roles'].' as role', 'role.id', '=', 'assigned.role_id')
            ->join('users', 'users.id', '=', 'assigned.model_id')
            ->where('assigned.model_type', (new User)->getMorphClass())
            ->where('role.name', OrganizationRole::Owner->value)
            ->whereIn("assigned.{$teamKey}", $organizationIds)
            ->orderBy('users.name')
            ->get(["assigned.{$teamKey} as organization_id", 'users.name', 'users.email']);

        $owners = [];

        foreach ($rows as $row) {
            $owners[(string) $row->organization_id][] = ['name' => (string) $row->name, 'email' => (string) $row->email];
        }

        return $owners;
    }
}
