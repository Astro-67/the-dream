<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Isolation\RouteCoverage;

test('a platform administrator sees every organization with its owner, property count and created date', function () {
    $tenants = twoTenants();
    $admin = platformStaff();

    $this->actingAs($admin)
        ->get(route('platform.organizations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/organizations/index')
            ->has('organizations', 2)
            ->where('organizations.0.name', 'Alpha Hotels Group')
            ->where('organizations.0.owners', [['name' => 'Alpha Owner', 'email' => 'owner@alpha.test']])
            ->where('organizations.0.propertyCount', 2)
            ->where('organizations.0.createdAt', $tenants->a->organization->created_at?->toIso8601String())
            ->where('organizations.1.name', 'Bravo Hotels Group'));
});

test('the organization list shows nothing from inside organizations', function () {
    twoTenants();

    $this->actingAs(platformStaff())
        ->get(route('platform.organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('organizations.0', fn (Assert $row) => $row
                ->hasAll(['id', 'name', 'owners', 'propertyCount', 'createdAt', 'goLiveRequested']))
            ->where('tenant.organization', null)
            ->where('tenant.property', null)
            ->where('auth.permissions', []));
});

test('the organization list marks only organizations with a waiting go-live request', function () {
    $tenants = twoTenants();
    $tenants->a->property->forceFill(['status' => 'setup', 'go_live_requested_at' => now()])->save();
    $tenants->b->property->forceFill(['go_live_requested_at' => now()])->save();

    $this->actingAs(platformStaff())->get(route('platform.organizations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('organizations.0.goLiveRequested', true)
            ->where('organizations.1.goLiveRequested', false));

    $tenants->a->property->forceFill(['archived_at' => now()])->save();
    $this->get(route('platform.organizations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('organizations.0.goLiveRequested', false));
});

test('organization members, owners included, cannot reach platform pages', function () {
    $tenants = twoTenants();
    $ownerWithTwoFactor = $tenants->a->owner;
    $ownerWithTwoFactor->forceFill(User::factory()->withTwoFactor()->raw())->save();

    foreach ([...$tenants->a->members(), 'owner with 2FA' => $ownerWithTwoFactor] as $member) {
        $this->actingAs($member)
            ->get(route('platform.organizations.index'))
            ->assertForbidden();
    }
});

test('a compliance operator cannot reach the organization list', function () {
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))
        ->get(route('platform.organizations.index'))
        ->assertForbidden();
});

test('a platform administrator without two-factor authentication is sent to turn it on', function () {
    $this->actingAs(platformStaff(twoFactor: false))
        ->get(route('platform.organizations.index'))
        ->assertRedirect(route('security.edit'));
});

test('viewing the organization list is logged through the platform context', function () {
    twoTenants();
    $admin = platformStaff();

    $this->actingAs($admin)->get(route('platform.organizations.index'))->assertOk();

    $entry = Activity::query()->where('log_name', 'platform')->where('event', 'platform_context_entered')->sole();
    expect($entry->causer?->is($admin))->toBeTrue()
        ->and($entry->getExtraProperty('reason'))->toBe('Platform administrator listed organizations');
});

test('every page tells the interface which platform roles the user holds, for navigation', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs(platformStaff())
        ->get(route('platform.organizations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.platformRoles', ['platform_administrator']));

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.platformRoles', []));
});

test('a platform administrator gets no way into an organization\'s pages, and lands on the platform pages instead', function () {
    $tenants = twoTenants();
    $admin = platformStaff();

    $response = $this->actingAs($admin)
        ->withSession(['tenancy.organization_id' => $tenants->b->organization->id, 'tenancy.property_id' => $tenants->b->property->id])
        ->get(route('dashboard'))
        ->assertRedirect(route('platform.organizations.index'));

    RouteCoverage::assertNothingOf($tenants->b, $response);
});

test('a compliance operator without a membership is still sent to organization setup from operational pages', function () {
    $this->actingAs(platformStaff(PlatformRole::ComplianceOperator))
        ->get(route('dashboard'))
        ->assertRedirect(route('organizations.create'));
});
