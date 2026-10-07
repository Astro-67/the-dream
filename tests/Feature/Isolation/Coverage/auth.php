<?php

use Laravel\Passkeys\Passkey;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

/*
 * Isolation coverage for Fortify and passkey routes: sign-in, registration and
 * the signed-in user's own credentials. None of them read tenant data, except
 * where a guessed ID could reach another user. See README.md.
 */
$ownAccount = 'Acts only on the signed-in user\'s own credentials; no tenant data.';
$guestFlow = 'Sign-in, registration or password-reset flow; no tenant data.';

return [
    'login' => RouteCoverage::exempt($guestFlow),
    'login.store' => RouteCoverage::exempt($guestFlow),
    'logout' => RouteCoverage::exempt($guestFlow),
    'register' => RouteCoverage::exempt($guestFlow),
    'register.store' => RouteCoverage::exempt($guestFlow),
    'password.request' => RouteCoverage::exempt($guestFlow),
    'password.email' => RouteCoverage::exempt($guestFlow),
    'password.reset' => RouteCoverage::exempt($guestFlow),
    'password.update' => RouteCoverage::exempt($guestFlow),
    'two-factor.login' => RouteCoverage::exempt($guestFlow),
    'two-factor.login.store' => RouteCoverage::exempt($guestFlow),
    'passkey.login' => RouteCoverage::exempt($guestFlow),
    'passkey.login-options' => RouteCoverage::exempt($guestFlow),

    'verification.notice' => RouteCoverage::exempt($ownAccount),
    'verification.send' => RouteCoverage::exempt($ownAccount),
    'password.confirm' => RouteCoverage::exempt($ownAccount),
    'password.confirm.store' => RouteCoverage::exempt($ownAccount),
    'password.confirmation' => RouteCoverage::exempt($ownAccount),
    'passkey.confirm' => RouteCoverage::exempt($ownAccount),
    'passkey.confirm-options' => RouteCoverage::exempt($ownAccount),
    'two-factor.enable' => RouteCoverage::exempt($ownAccount),
    'two-factor.disable' => RouteCoverage::exempt($ownAccount),
    'two-factor.confirm' => RouteCoverage::exempt($ownAccount),
    'two-factor.qr-code' => RouteCoverage::exempt($ownAccount),
    'two-factor.secret-key' => RouteCoverage::exempt($ownAccount),
    'two-factor.recovery-codes' => RouteCoverage::exempt($ownAccount),
    'two-factor.regenerate-recovery-codes' => RouteCoverage::exempt($ownAccount),
    'passkey.registration-options' => RouteCoverage::exempt($ownAccount),
    'passkey.store' => RouteCoverage::exempt($ownAccount),

    // A guessed verification link for a Bravo member is refused: the link must be signed.
    'verification.verify' => RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)
        ->get(route('verification.verify', ['id' => $t->b->owner->id, 'hash' => sha1($t->b->owner->email)]))
        ->assertForbidden()),

    // Deleting a Bravo member's passkey by its ID is refused, and the passkey stays.
    'passkey.destroy' => RouteCoverage::attempt(function (TwoTenants $t) {
        $passkey = $t->b->owner->passkeys()->create([
            'name' => 'Bravo Owner YubiKey',
            'credential_id' => 'bravo-credential',
            'credential' => ['id' => 'bravo-credential'],
        ]);

        $response = $this->actingAs($t->a->owner)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('passkey.destroy', $passkey));

        expect($response->status())->toBeIn([403, 404])
            ->and(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();

        return $response;
    }),
];
