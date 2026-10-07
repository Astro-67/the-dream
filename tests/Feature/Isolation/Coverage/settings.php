<?php

use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for routes/settings.php (the user's own account). See README.md.
 */
return [
    'settings' => RouteCoverage::visit(),

    'profile.edit' => RouteCoverage::visit(),

    // Taking a Bravo member's email address would hand over their sign-in; it is refused.
    'profile.update' => RouteCoverage::attempt(function (TwoTenants $t) {
        $response = $this->actingAs($t->a->owner)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), ['name' => 'Alpha Owner', 'email' => $t->b->owner->email])
            ->assertSessionHasErrors('email');

        expect($t->a->owner->email)->toBe('owner@alpha.test');

        return $response;
    }),

    'profile.destroy' => RouteCoverage::exempt('Deletes only the signed-in user\'s own account.'),

    'security.edit' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertOk()),

    'user-password.update' => RouteCoverage::exempt('Changes only the signed-in user\'s own password.'),

    'appearance.edit' => RouteCoverage::visit(),

    'well-known.passkeys' => RouteCoverage::visit(),
];
