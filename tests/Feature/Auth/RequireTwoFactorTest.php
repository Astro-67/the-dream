<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * The `two-factor` route middleware, as any route uses it (platform pages now,
 * owner-only actions later).
 */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'two-factor'])->group(function () {
        Route::get('_test/two-factor-gated', fn () => 'reached');
        Route::post('_test/two-factor-gated', fn () => 'changed');
    });
});

test('a user with two-factor authentication on passes', function () {
    $this->actingAs(User::factory()->withTwoFactor()->create())
        ->get('_test/two-factor-gated')
        ->assertOk()
        ->assertSee('reached');
});

test('a user without it is sent to the security settings to turn it on, with a message', function () {
    $this->actingAs(User::factory()->create())
        ->post('_test/two-factor-gated')
        ->assertRedirect(route('security.edit'))
        ->assertInertiaFlash('toast', ['type' => 'error', 'message' => 'Turn on two-factor authentication to continue.']);
});

test('set up but not yet confirmed does not count', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_confirmed_at' => null]);

    $this->actingAs($user)
        ->get('_test/two-factor-gated')
        ->assertRedirect(route('security.edit'));
});

test('a JSON request without it is refused', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('_test/two-factor-gated')
        ->assertForbidden();
});
