<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

test('a platform administrator sees the platform staff and their roles', function () {
    $admin = platformStaff(attributes: ['name' => 'Asha Admin', 'email' => 'asha@thedream.test']);
    platformStaff(PlatformRole::ComplianceOperator, attributes: ['name' => 'Omar Operator', 'email' => 'omar@thedream.test']);
    User::factory()->create(['email' => 'guest-user@example.test']);

    $this->actingAs($admin)
        ->get(route('platform.staff.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/staff/index')
            ->has('staff', 2)
            ->where('staff.0.name', 'Asha Admin')
            ->where('staff.0.email', 'asha@thedream.test')
            ->where('staff.0.roles', ['platform_administrator'])
            ->where('staff.0.twoFactorEnabled', true)
            ->where('staff.1.name', 'Omar Operator')
            ->where('staff.1.roles', ['compliance_operator'])
            ->where('roles', [
                ['value' => 'platform_administrator', 'label' => 'Platform administrator'],
                ['value' => 'compliance_operator', 'label' => 'Compliance operator'],
            ]));
});

test('a platform administrator gives an existing user a platform role', function () {
    $admin = platformStaff();
    $user = User::factory()->create(['email' => 'new.operator@thedream.test']);

    $this->actingAs($admin)
        ->post(route('platform.staff.store'), ['email' => 'new.operator@thedream.test', 'role' => 'compliance_operator'])
        ->assertRedirect(route('platform.staff.index'));

    expect($user->hasPlatformRole(PlatformRole::ComplianceOperator))->toBeTrue();

    $entry = Activity::query()->where('event', 'platform_role_assigned')->latest('id')->first();
    expect($entry?->causer?->is($admin))->toBeTrue()
        ->and($entry?->subject?->is($user))->toBeTrue();
});

test('assigning a platform role needs a registered email and a platform role', function () {
    $admin = platformStaff();
    User::factory()->create(['email' => 'someone@thedream.test']);

    $this->actingAs($admin)
        ->post(route('platform.staff.store'), ['email' => 'nobody@thedream.test', 'role' => 'compliance_operator'])
        ->assertSessionHasErrors('email');

    $this->actingAs($admin)
        ->post(route('platform.staff.store'), ['email' => 'someone@thedream.test', 'role' => 'owner'])
        ->assertSessionHasErrors('role');
});

test('a platform administrator takes a platform role away', function () {
    $admin = platformStaff();
    $operator = platformStaff(PlatformRole::ComplianceOperator);

    $this->actingAs($admin)
        ->delete(route('platform.staff.destroy', ['user' => $operator, 'role' => 'compliance_operator']))
        ->assertRedirect(route('platform.staff.index'));

    expect($operator->hasPlatformRole(PlatformRole::ComplianceOperator))->toBeFalse();
});

test('a platform administrator cannot take away their own administrator role', function () {
    $admin = platformStaff();

    $this->actingAs($admin)
        ->delete(route('platform.staff.destroy', ['user' => $admin, 'role' => 'platform_administrator']))
        ->assertSessionHasErrors('role');

    expect($admin->hasPlatformRole(PlatformRole::Administrator))->toBeTrue();
});

test('organization members, owners included, and compliance operators cannot assign platform roles', function () {
    $tenants = twoTenants();
    $owner = $tenants->a->owner;
    $owner->forceFill(User::factory()->withTwoFactor()->raw())->save();
    $operator = platformStaff(PlatformRole::ComplianceOperator);

    foreach ([$owner, $operator] as $actor) {
        $this->actingAs($actor)
            ->post(route('platform.staff.store'), ['email' => $owner->email, 'role' => 'platform_administrator'])
            ->assertForbidden();
    }

    expect($owner->hasPlatformRole(PlatformRole::Administrator))->toBeFalse()
        ->and(Activity::query()->where('event', 'platform_role_assigned')->count())->toBe(1);
});
