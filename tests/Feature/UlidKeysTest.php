<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

test('a newly registered user is identified by a ULID', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    expect(Str::isUlid(Auth::id()))->toBeTrue();
    expect(User::find(Auth::id())?->email)->toBe('test@example.com');
});

test('a user can hold a role and a direct permission within a ULID-keyed organization', function () {
    $user = User::factory()->create();
    $organizationId = (string) Str::ulid();
    $otherOrganizationId = (string) Str::ulid();

    setPermissionsTeamId($organizationId);
    $role = Role::create(['name' => 'front desk']);
    Permission::create(['name' => 'reservations.view']);
    $user->assignRole($role);
    $user->givePermissionTo('reservations.view');

    expect($user->fresh()->hasRole('front desk'))->toBeTrue();
    expect($user->fresh()->hasDirectPermission('reservations.view'))->toBeTrue();

    setPermissionsTeamId($otherOrganizationId);

    expect($user->fresh()->hasRole('front desk'))->toBeFalse();
    expect($user->fresh()->hasDirectPermission('reservations.view'))->toBeFalse();
});
