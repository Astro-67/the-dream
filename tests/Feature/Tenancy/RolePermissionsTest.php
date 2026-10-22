<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Models\User;
use App\Support\Tenancy\Access;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

/*
 * Access is decided by the member's role permission AND the record's property (ADR 0007).
 * Each role is checked in all four properties of the two-organization fixture, from inside
 * Alpha's organization, the way a request to Alpha runs.
 */

/**
 * Whether the member may use the permission in each property of both organizations,
 * keyed "a.property", "a.otherProperty", "b.property", "b.otherProperty".
 *
 * @return array<string, bool>
 */
function accessAcrossTenants(TwoTenants $t, User $member, OrganizationPermission $permission): array
{
    return inTenant($t->a->organization, fn () => [
        'a.property' => Access::allows($member, $permission, $t->a->property),
        'a.otherProperty' => Access::allows($member, $permission, $t->a->otherProperty),
        'b.property' => Access::allows($member, $permission, $t->b->property),
        'b.otherProperty' => Access::allows($member, $permission, $t->b->otherProperty),
    ]);
}

function onlyIn(string ...$properties): array
{
    $all = ['a.property' => false, 'a.otherProperty' => false, 'b.property' => false, 'b.otherProperty' => false];

    foreach ($properties as $property) {
        $all[$property] = true;
    }

    return $all;
}

test('a front-desk member works only in their own property', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);

    expect(accessAcrossTenants($t, $frontDesk, OrganizationPermission::ReservationsManage))->toBe(onlyIn('a.property'))
        ->and(accessAcrossTenants($t, $frontDesk, OrganizationPermission::GuestIdentityView))->toBe(onlyIn('a.property'));
});

test('an auditor may view permitted records but every change is refused', function () {
    $t = twoTenants();
    $auditor = $t->a->member(OrganizationRole::Auditor);

    foreach (OrganizationPermission::cases() as $permission) {
        if (! $permission->isReadOnly()) {
            expect(accessAcrossTenants($t, $auditor, $permission))->toBe(onlyIn(), $permission->value);
        }
    }

    expect(accessAcrossTenants($t, $auditor, OrganizationPermission::ReservationsView))->toBe(onlyIn('a.property'))
        ->and(accessAcrossTenants($t, $auditor, OrganizationPermission::FinanceView))->toBe(onlyIn('a.property'))
        ->and(accessAcrossTenants($t, $auditor, OrganizationPermission::GuestIdentityView))->toBe(onlyIn());
});

test('a housekeeping member is refused finance and guest identity data', function () {
    $t = twoTenants();
    $housekeeping = $t->a->member(OrganizationRole::Housekeeping);

    foreach ([OrganizationPermission::FinanceView, OrganizationPermission::FinanceCorrect, OrganizationPermission::ChargesPost, OrganizationPermission::ReportsView, OrganizationPermission::GuestsView, OrganizationPermission::GuestIdentityView] as $permission) {
        expect(accessAcrossTenants($t, $housekeeping, $permission))->toBe(onlyIn(), $permission->value);
    }

    expect(accessAcrossTenants($t, $housekeeping, OrganizationPermission::HousekeepingManage))->toBe(onlyIn('a.property'));
});

test('all-properties members reach every property of their organization and nothing outside it', function (OrganizationRole $role) {
    $t = twoTenants();

    expect(accessAcrossTenants($t, $t->a->member($role), OrganizationPermission::PropertiesUpdate))->toBe(onlyIn('a.property', 'a.otherProperty'));
})->with([OrganizationRole::Owner, OrganizationRole::GroupOperationsManager]);

test('a member of another organization is refused everything, whatever their role there', function () {
    $t = twoTenants();
    $bravoOwner = $t->b->owner;

    foreach (OrganizationPermission::cases() as $permission) {
        expect(accessAcrossTenants($t, $bravoOwner, $permission))->toBe(onlyIn(), $permission->value)
            ->and(inTenant($t->a->organization, fn () => Access::allows($bravoOwner, $permission)))->toBeFalse();
    }
});

test('each role uses exactly its permissions, only in the properties it may act in', function (OrganizationRole $role) {
    $t = twoTenants();
    $member = $t->a->member($role);
    $reach = $role->mayHaveAllProperties() ? onlyIn('a.property', 'a.otherProperty') : onlyIn('a.property');

    foreach (OrganizationPermission::cases() as $permission) {
        $expected = in_array($permission->value, $role->permissions(), true) ? $reach : onlyIn();

        expect(accessAcrossTenants($t, $member, $permission))->toBe($expected, "{$role->value}: {$permission->value}");
    }
})->with(OrganizationRole::cases());

test('a group operations manager uses financial settings only when granted them', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);

    expect(accessAcrossTenants($t, $manager, OrganizationPermission::FinanceSettingsManage))->toBe(onlyIn());

    inTenant($t->a->organization, fn () => $manager->givePermissionTo(OrganizationPermission::FinanceSettingsManage->value));

    expect(accessAcrossTenants($t, $manager->fresh(), OrganizationPermission::FinanceSettingsManage))->toBe(onlyIn('a.property', 'a.otherProperty'))
        ->and(accessAcrossTenants($t, $manager->fresh(), OrganizationPermission::SecuritySettingsManage))->toBe(onlyIn());
});

test('the page data carries the member\'s permissions for the current property', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::Housekeeping))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.id', $t->a->property->id)
            ->where('auth.permissions', ['housekeeping.manage', 'housekeeping.view', 'maintenance.report', 'maintenance.view']));

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', [
            'finance.view', 'guests.view', 'housekeeping.view', 'members.view',
            'night_audit.view', 'properties.view', 'reports.view', 'reservations.view',
        ]));
});

test('nothing is allowed outside a tenant context', function () {
    $t = twoTenants();

    expect(Access::allows($t->a->owner, OrganizationPermission::PropertiesView, $t->a->property))->toBeFalse()
        ->and(Access::permissionsFor($t->a->owner, $t->a->property))->toBe([]);
});

test('the common staff roles reach exactly their routes, in their own property only', function (OrganizationRole $role, array $allowed) {
    $t = twoTenants();
    [$here, $there, $foreign] = [[$t->a, $t->a->property], [$t->a, $t->a->otherProperty], [$t->b, $t->b->property]];
    $folios = array_map(fn (array $at) => inTenant($at[1], fn () => Folio::query()->where('reservation_id', app(CreateReservation::class)->handle($at[0]->owner, BookingForm::details($at[0]->owner))->id)->sole()), [$here, $there, $foreign]);
    $this->actingAs($t->a->member($role))->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id]);
    $range = ['from' => '2026-12-01', 'to' => '2026-12-31'];
    $pages = [
        'reservations' => route('reservations.index'),
        'housekeeping' => route('housekeeping.index'),
        'payments' => route('payments.index', $range),
        'reports' => route('reports.index', $range),
        'guests' => route('guests.index'),
        'staff' => route('members.index'),
        'add staff' => route('invitations.create'),
    ];

    foreach ($pages as $page => $url) {
        expect($this->get($url)->status())->toBe(in_array($page, $allowed, true) ? 200 : 403, "{$role->value}: {$page}");
    }
    foreach ($folios as $i => $folio) {
        $status = $this->post(route('folios.payments.store', $folio), ['_property' => $t->a->property->id, 'method' => 'bank_transfer', 'amount' => 100, 'idempotency_key' => "k-{$i}"])->status();
        expect($status)->toBe(match (true) {
            // Folios of another property, even in the same organization, are not found from this one.
            $i > 0 => 404,
            in_array('take payment', $allowed, true) => 302,
            default => 403,
        }, "{$role->value}: payment {$i}");
    }
    expect(inTenant($t->a->otherProperty, fn () => Posting::query()->count()))->toBe(0)
        ->and(inTenant($t->b->property, fn () => Posting::query()->count()))->toBe(0);
})->with([
    // Payments here is the read-only report; a property manager still may not take a payment.
    'property manager' => [OrganizationRole::PropertyManager, ['reservations', 'housekeeping', 'payments', 'reports', 'guests', 'staff', 'add staff']],
    'reception' => [OrganizationRole::FrontDesk, ['reservations', 'housekeeping', 'guests', 'take payment']],
    'housekeeping' => [OrganizationRole::Housekeeping, ['housekeeping']],
]);
