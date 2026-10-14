<?php

use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as RegisteredRoute;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\PropertyForm;

/*
 * Owners must have two-factor authentication on for owner-only actions:
 * managing properties and members, ownership and organization settings (#13).
 * A route is owner-only when it takes the `two-factor:owner` middleware.
 */

const OWNER_TWO_FACTOR_MESSAGE = 'Owners need two-factor authentication to manage properties, members and organization settings. Turn it on to continue.';

test('an owner without it is sent to set it up from every owner-only action, with an explanation', function () {
    [$owner, , $property] = organizationWithOwner(owner: User::factory()->create());
    $this->actingAs($owner);

    expect(ownerOnlyRouteNames())->toContain('properties.update', 'properties.archive', 'invitations.store', 'invitations.destroy');

    foreach (ownerOnlyRouteNames() as $routeName) {
        [$method, $url, $data] = [...ownerOnlyRequest($routeName, $owner, $property), []];

        $this->call($method, $url, $data)
            ->assertRedirect(route('security.edit'))
            ->assertInertiaFlash('toast', ['type' => 'error', 'message' => OWNER_TWO_FACTOR_MESSAGE]);
    }

    expect($property->fresh())
        ->name->toBe('Stone Town Inn')
        ->archived_at->toBeNull();
});

/**
 * @return list<string>
 */
function ownerOnlyRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RegisteredRoute $route) => in_array('two-factor:owner', $route->gatherMiddleware(), true))
        ->map(fn (RegisteredRoute $route) => (string) $route->getName())
        ->values()
        ->all();
}

/**
 * The request an owner makes for an owner-only action in their organization.
 * A new owner-only route is added here.
 *
 * @return array{0: string, 1: string, 2?: array<string, mixed>}
 */
function ownerOnlyRequest(string $routeName, User $owner, Property $property): array
{
    $intent = ['_property' => $property->id];

    return match ($routeName) {
        'properties.index', 'properties.create', 'invitations.index', 'invitations.create' => ['GET', route($routeName)],
        'properties.store' => ['POST', route($routeName), PropertyForm::payload($intent)],
        'properties.edit' => ['GET', route($routeName, $property)],
        'properties.update' => ['PUT', route($routeName, $property), PropertyForm::payload($intent)],
        'properties.archive' => ['POST', route($routeName, $property), $intent],
        'properties.restore' => ['DELETE', route($routeName, $property), $intent],
        'properties.go-live', 'properties.taxes-reviewed' => ['POST', route($routeName, $property), $intent],
        'invitations.store' => ['POST', route($routeName), [...$intent, 'email' => 'new@example.test', 'role' => 'front_desk', 'all_properties' => false, 'property_ids' => [$property->id], 'expires_in_days' => 7]],
        'invitations.destroy' => ['DELETE', route($routeName, inTenant($property, fn () => app(SendInvitation::class)->handle(
            $owner,
            new InvitationDetails('pending@example.test', MembershipGrant::properties(OrganizationRole::FrontDesk, [$property->id]), CarbonImmutable::now()->addWeek()),
        ))), $intent],
        'members.index', 'subscription.show' => ['GET', route($routeName)],
        'members.edit' => ['GET', route($routeName, ownMembership($owner, $property))],
        'members.update' => ['PUT', route($routeName, ownMembership($owner, $property)), [...$intent, 'role' => 'group_operations_manager', 'all_properties' => true]],
        'members.destroy' => ['DELETE', route($routeName, ownMembership($owner, $property)), $intent],
        'members.transfer-ownership' => ['POST', route($routeName, ownMembership($owner, $property)), [...$intent, 'role' => 'owner', 'all_properties' => true]],
        default => throw new LogicException("Add the owner-only route [{$routeName}] to ownerOnlyRequest()."),
    };
}

function ownMembership(User $owner, Property $property): OrganizationMembership
{
    return inTenant($property, fn () => OrganizationMembership::query()->where('user_id', $owner->id)->sole());
}

test('after turning on two-factor authentication, the owner returns to what they were doing and completes it', function () {
    [$owner, , $property] = organizationWithOwner(owner: User::factory()->create());
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('properties.edit', $property))
        ->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('twoFactorEnabled', false)
            ->where('twoFactorRequired', ['reason' => OWNER_TWO_FACTOR_MESSAGE, 'returnTo' => route('properties.edit', $property)]));

    turnOnTwoFactor($owner);

    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('twoFactorEnabled', true)
            ->where('twoFactorRequired', ['reason' => OWNER_TWO_FACTOR_MESSAGE, 'returnTo' => route('properties.edit', $property)]));

    $this->get(route('properties.edit', $property))->assertOk();
    $this->put(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id]))
        ->assertRedirect(route('properties.index'));

    expect($property->fresh()->name)->toBe('Serengeti Gate Lodge');

    // Back where they were, so there is nothing left to return to.
    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', null));
});

test('an owner sent away from a form returns to the page the form was on', function () {
    [$owner, , $property] = organizationWithOwner(owner: User::factory()->create());
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->from(route('properties.index'))
        ->post(route('properties.archive', $property), ['_property' => $property->id])
        ->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', ['reason' => OWNER_TWO_FACTOR_MESSAGE, 'returnTo' => route('properties.index')]));
});

test('a form posted from another site is not returned to', function () {
    [$owner, , $property] = organizationWithOwner(owner: User::factory()->create());
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->from('https://elsewhere.example/'.parse_url(route('properties.index'), PHP_URL_HOST))
        ->post(route('properties.archive', $property), ['_property' => $property->id])
        ->assertRedirect(route('security.edit'));

    $this->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('twoFactorRequired', ['reason' => OWNER_TWO_FACTOR_MESSAGE, 'returnTo' => null]));
});

test('an owner who turns two-factor authentication off loses owner-only access until it is back on', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('properties.index'))->assertOk();

    $this->delete(route('two-factor.disable'))->assertSessionHasNoErrors();

    $this->get(route('properties.index'))->assertRedirect(route('security.edit'));
    $this->get(route('invitations.index'))->assertRedirect(route('security.edit'));

    turnOnTwoFactor($t->a->owner);

    $this->get(route('properties.index'))->assertOk();
    $this->get(route('invitations.index'))->assertOk();
});

test('other roles use the same pages without two-factor authentication', function () {
    $t = twoTenants();
    $groupManager = $t->a->member(OrganizationRole::GroupOperationsManager);
    $propertyManager = $t->a->member(OrganizationRole::PropertyManager);

    expect($groupManager->hasEnabledTwoFactorAuthentication())->toBeFalse()
        ->and($propertyManager->hasEnabledTwoFactorAuthentication())->toBeFalse();

    $this->actingAs($groupManager)->get(route('properties.create'))->assertOk();
    $this->actingAs($propertyManager)->get(route('properties.edit', $t->a->property))->assertOk();
    $this->actingAs($propertyManager)->get(route('invitations.create'))->assertOk();
});

test('only owners of the organization they are working in are asked for it', function () {
    $t = twoTenants();
    $user = $t->memberOfBoth(OrganizationRole::Owner, OrganizationRole::GroupOperationsManager);
    $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

    $this->actingAs($user)
        ->get(route('properties.index'))
        ->assertRedirect(route('security.edit'));

    $this->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id]);

    $this->get(route('properties.index'))->assertOk();
});

test('a JSON request from an owner without it is refused', function () {
    [$owner] = organizationWithOwner(owner: User::factory()->create());

    $this->actingAs($owner)
        ->getJson(route('invitations.index'))
        ->assertForbidden();
});

/**
 * Turns on two-factor authentication for the signed-in user through the security
 * settings, as they would: enable it, then confirm with a code from their app.
 */
function turnOnTwoFactor(User $user): void
{
    test()->post(route('two-factor.enable'))->assertSessionHasNoErrors();

    $code = app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));

    test()->post(route('two-factor.confirm'), ['code' => $code])->assertSessionHasNoErrors();

    expect($user->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
}
