<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

test('a console command makes a user a platform administrator', function () {
    twoTenants();
    $user = User::factory()->withTwoFactor()->create(['email' => 'support@thedream.test']);

    $this->artisan('platform:assign-role', ['email' => 'support@thedream.test', 'role' => 'platform_administrator'])
        ->assertSuccessful();

    $this->actingAs($user)->get(route('platform.organizations.index'))->assertOk();

    $entry = Activity::query()->where('event', 'platform_role_assigned')->sole();
    expect($entry->subject?->is($user))->toBeTrue()
        ->and($entry->causer)->toBeNull()
        ->and($entry->getExtraProperty('role'))->toBe('platform_administrator');
});

test('a console command takes a platform role away, and access ends at once', function () {
    $admin = platformStaff(attributes: ['email' => 'support@thedream.test']);
    $this->actingAs($admin)->get(route('platform.organizations.index'))->assertOk();

    $this->artisan('platform:revoke-role', ['email' => 'support@thedream.test', 'role' => 'platform_administrator'])
        ->assertSuccessful();

    $this->get(route('platform.organizations.index'))->assertForbidden();
    expect(Activity::query()->where('event', 'platform_role_revoked')->count())->toBe(1);
});

test('the console command refuses an unknown user or role', function () {
    User::factory()->create(['email' => 'support@thedream.test']);

    $this->artisan('platform:assign-role', ['email' => 'nobody@thedream.test', 'role' => 'platform_administrator'])
        ->assertFailed();
    $this->artisan('platform:assign-role', ['email' => 'support@thedream.test', 'role' => 'owner'])
        ->assertFailed();

    expect(Activity::query()->where('event', 'platform_role_assigned')->count())->toBe(0);
});
