<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Organization;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/**
 * The organization's roles as seeded: role name => sorted permission names.
 *
 * @return array<string, list<string>>
 */
function seededRoles(Organization $organization): array
{
    return Role::query()
        ->where('organization_id', $organization->id)
        ->with('permissions')
        ->orderBy('name')
        ->get()
        ->mapWithKeys(fn (Role $role) => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->all();
}

test('each new organization is seeded with the seven roles and their permission sets', function () {
    [, $organization] = organizationWithOwner();

    $roles = seededRoles($organization);

    expect(array_keys($roles))->toEqualCanonicalizing(array_map(fn (OrganizationRole $r) => $r->value, OrganizationRole::cases()))
        ->and($roles['housekeeping'])->toBe(['housekeeping.manage', 'housekeeping.view'])
        ->and($roles['front_desk'])->toBe([
            'charges.post', 'guests.identity.view', 'guests.register', 'guests.view',
            'housekeeping.view', 'night_audit.view', 'reservations.manage', 'reservations.view', 'rooms.assign',
        ]);

    foreach (OrganizationRole::cases() as $role) {
        expect($roles[$role->value])->toEqualCanonicalizing($role->permissions());
    }
});

test('syncing roles brings every organization up to date with the defined permission sets', function () {
    $t = twoTenants();

    // Roles seeded under an older definition: a permission since withdrawn, one since added.
    foreach ([$t->a->organization, $t->b->organization] as $organization) {
        inTenant($organization, function () {
            $frontDesk = Role::findByName(OrganizationRole::FrontDesk->value);
            $frontDesk->givePermissionTo('finance.correct');
            $frontDesk->revokePermissionTo('reservations.manage');
        });
    }

    $this->artisan('organizations:sync-roles')->assertSuccessful();

    foreach ([$t->a->organization, $t->b->organization] as $organization) {
        expect(seededRoles($organization)['front_desk'])->toEqualCanonicalizing(OrganizationRole::FrontDesk->permissions());
    }

    expect(Activity::query()->where('log_name', 'platform')->latest('id')->first()?->getExtraProperty('reason'))
        ->toBe('Sync organization roles with their defined permission sets');
});
