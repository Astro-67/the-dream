<?php

use App\Domains\Tenancy\Actions\AddOrganizationMember;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The first-run journey: register, verify, set up the organization, then the
 * dashboard. Each step's redirect and feedback, and the abuse limits around it.
 */

beforeEach(fn () => RateLimiter::clear('account-forms:register.store|127.0.0.1'));

test('a new account walks register, verify, organization setup and lands on the setup checklist', function (array $registration) {
    $this->post(route('register.store'), [
        'name' => 'Amina Hassan',
        'email' => 'amina@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get(route('organizations.create'))->assertRedirect(route('verification.notice'));
    $this->get(route('verification.notice'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/verify-email')
        ->where('email', 'amina@example.com'));

    $user = User::query()->sole();
    $user->markEmailAsVerified();
    $this->actingAs($user);

    $this->get(route('dashboard'))->assertRedirect(route('organizations.create'));
    $this->post(route('organizations.store'), organizationSetupPayload($registration))
        ->assertRedirect(route('dashboard'))
        ->assertInertiaFlash('toast.type', 'success');

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.propertyName', 'Stone Town Inn')
        ->where('today', null));

    $property = Tenancy::within(Tenancy::defaultContextFor($user), fn () => Property::query()->sole());
    expect($property->only(['star_rating', 'tin', 'z_number', 'vrn']))->toEqual([
        'star_rating' => $registration['star_rating'] ?? null,
        'tin' => $registration['tin'] ?? null,
        'z_number' => $registration['z_number'] ?? null,
        'vrn' => $registration['vrn'] ?? null,
    ]);
})->with([
    'registration omitted' => [[]],
    'not yet classified and empty registration' => [['star_rating' => null, 'tin' => null, 'z_number' => null, 'vrn' => null]],
    'classified and registered' => [['star_rating' => 2, 'tin' => 'TIN-122', 'z_number' => 'Z-122/LOCATION', 'vrn' => 'VRN-122']],
]);

test('registering with an email that already has an account is refused with a field error', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => 'taken@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('the registration form states the password policy before the user types', function () {
    $this->get(route('register'))->assertInertia(fn (Assert $page) => $page
        ->where('passwordHint', 'At least 8 characters.'));
});

test('registration is throttled per IP and refused with a form error', function (int $elapsedSeconds) {
    $this->freezeTime();

    foreach (range(1, 5) as $i) {
        $this->post(route('register.store'), ['name' => 'x', 'email' => "bad{$i}"]);
    }

    $this->travel($elapsedSeconds)->seconds();

    $this->post(route('register.store'), [
        'name' => 'Late',
        'email' => 'late@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors(['email' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => 60 - $elapsedSeconds])]);

    $this->assertGuest();

    expect(User::query()->where('email', 'late@example.com')->exists())->toBeFalse();

    $this->travel(60 - $elapsedSeconds)->seconds();

    $this->post(route('register.store'), [
        'name' => 'Late',
        'email' => 'late@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
})->with(['immediate refusal' => 0, 'one second later' => 1]);

test('the forgot-password form answers the same for unknown and known emails', function () {
    Notification::fake();
    RateLimiter::clear('account-forms:password.email|127.0.0.1');
    $user = User::factory()->create();

    $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);
    $known = $this->post(route('password.email'), ['email' => $user->email]);

    $unknown->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    $known->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'));
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

test('logging out clears the browser history of tenant pages', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('home'));

    $this->assertGuest();
    expect($this->get(route('home'))->viewData('page')['clearHistory'] ?? false)->toBeTrue();
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('a live property dashboard shows today\'s arrivals, departures and in-house counts', function () {
    [$owner, , $property] = organizationWithOwner();

    $this->actingAs($owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding', null)
        ->where('today.businessDate', $property->business_date->toDateString())
        ->where('today.lists.0.key', 'arrivals')
        ->where('today.lists.0.count', 0)
        ->has('today.lists', 3));
});

test('checklist steps link only where the member may go', function () {
    [, $organization, $property] = organizationWithOwner();
    $property->forceFill(['status' => PropertyStatus::Setup])->save();
    $housekeeper = User::factory()->create();
    inTenant($organization, fn () => app(AddOrganizationMember::class)
        ->handle($housekeeper, MembershipGrant::properties(OrganizationRole::Housekeeping, [$property->id])));

    $this->actingAs($housekeeper)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('onboarding.items.0.key', 'verify_email')
        ->whereNot('onboarding.items.0.href', null)
        ->where('onboarding.items.1.key', 'property_profile')
        ->where('onboarding.items.1.href', null)
        ->where('onboarding.items.5.key', 'invite_staff')
        ->where('onboarding.items.5.href', null));
});
