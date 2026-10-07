<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Notifications\InvitationSent;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/**
 * Sends an invitation through the invite form as $inviter, signs them out, and
 * returns the link from the email.
 *
 * @param  array<string, mixed>  $input  invite form fields
 */
function invitationLink(User $inviter, array $input): string
{
    Notification::fake();

    test()->actingAs($inviter)
        ->post(route('invitations.store'), [
            'role' => 'front_desk',
            'all_properties' => false,
            'expires_in_days' => 7,
            ...$input,
        ])
        ->assertSessionHasNoErrors();

    $link = null;
    Notification::assertSentOnDemand(InvitationSent::class, function (InvitationSent $notification) use (&$link) {
        $link = $notification->url();

        return true;
    });

    auth()->logout();
    test()->flushSession();

    return $link;
}

test('an owner invites a person with a role, properties and an expiry, and the invitation is listed as pending', function () {
    Notification::fake();
    $this->freezeTime();
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('invitations.store'), [
            'email' => 'Amina@Example.test',
            'role' => 'front_desk',
            'all_properties' => false,
            'property_ids' => [$t->a->property->id],
            'expires_in_days' => 3,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('invitations.index'));

    $this->get(route('invitations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('invitations/index')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'amina@example.test')
            ->where('invitations.0.role', 'front_desk')
            ->where('invitations.0.allProperties', false)
            ->where('invitations.0.properties', [$t->a->property->name])
            ->where('invitations.0.invitedBy', 'Alpha Owner')
            ->where('invitations.0.expiresAt', now()->addDays(3)->toIso8601String())
        );

    Notification::assertSentOnDemand(InvitationSent::class, fn (InvitationSent $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'amina@example.test');
});

test('an existing user who accepts keeps one account, gains the membership and lands in the inviting organization', function () {
    $t = twoTenants();
    $bravoClerk = $t->b->member(OrganizationRole::FrontDesk);
    $link = invitationLink($t->a->owner, ['email' => $bravoClerk->email, 'role' => 'housekeeping', 'property_ids' => [$t->a->otherProperty->id]]);
    $users = User::query()->count();

    $this->actingAs($bravoClerk)->get($link)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/invitation')
            ->where('invitation.organization', 'Alpha Hotels Group')
            ->where('refusal', null));

    $this->post($link)->assertRedirect(route('dashboard'));

    expect(User::query()->count())->toBe($users);
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $t->a->organization->id)
            ->where('tenant.property.id', $t->a->otherProperty->id)
            ->has('tenant.organizations', 2)
            ->has('tenant.properties', 1));

    inTenant($t->a->organization, fn () => expect($bravoClerk->fresh()->hasRole('housekeeping'))->toBeTrue());
    inTenant($t->b->organization, fn () => expect($bravoClerk->fresh()->hasRole('front_desk'))->toBeTrue());
});

test('accepting with a different email address is refused', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'property_ids' => [$property->id]]);
    $someoneElse = User::factory()->create(['email' => 'zawadi@example.test']);

    $this->actingAs($someoneElse)->get($link)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('refusal', fn (string $refusal) => str_contains($refusal, 'different email address')));

    $this->post($link)->assertForbidden();

    expect(Tenancy::hasMembership($someoneElse))->toBeFalse();
    $this->get(route('dashboard'))->assertRedirect(route('organizations.create'));
});

test('a new person registers from the invitation and lands in the inviting organization without setting one up', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'role' => 'finance', 'property_ids' => [$property->id]]);

    $this->get($link)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('signedInAs', null)->where('refusal', null));

    $this->post(route('register.store'), [
        'name' => 'Amina Juma',
        'email' => 'amina@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect($link);

    $this->post($link)->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $organization->id)
            ->where('tenant.property.id', $property->id));
    expect(User::query()->where('email', 'amina@example.test')->count())->toBe(1);
});

test('an invitation can only be accepted once', function () {
    [$owner, , $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'property_ids' => [$property->id]]);
    $amina = User::factory()->create(['email' => 'amina@example.test']);

    $this->actingAs($amina)->post($link)->assertRedirect(route('dashboard'));
    $this->post($link)->assertRedirect($link);

    $this->get($link)->assertInertia(fn (Assert $page) => $page
        ->where('refusal', fn (string $refusal) => str_contains($refusal, 'already been used')));
});

test('an expired invitation shows a clear message and can\'t be accepted', function () {
    [$owner, , $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'property_ids' => [$property->id], 'expires_in_days' => 2]);
    $amina = User::factory()->create(['email' => 'amina@example.test']);

    $this->travel(3)->days();

    $this->actingAs($amina)->get($link)->assertInertia(fn (Assert $page) => $page
        ->where('refusal', fn (string $refusal) => str_contains($refusal, 'has expired')));
    $this->post($link)->assertRedirect($link);

    expect(Tenancy::hasMembership($amina))->toBeFalse();
});

test('invitations expire after 7 days by default', function () {
    [$owner, , $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'property_ids' => [$property->id], 'expires_in_days' => null]);
    $amina = User::factory()->create(['email' => 'amina@example.test']);

    $this->travel(6)->days();
    $this->actingAs($amina)->get($link)->assertInertia(fn (Assert $page) => $page->where('refusal', null));

    $this->travel(2)->days();
    $this->get($link)->assertInertia(fn (Assert $page) => $page
        ->where('refusal', fn (string $refusal) => str_contains($refusal, 'has expired')));
});

test('an owner revokes a pending invitation, which leaves the list and can\'t be accepted', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'property_ids' => [$property->id]]);
    $invitation = inTenant($organization, fn () => Invitation::query()->sole());

    $this->actingAs($owner)
        ->delete(route('invitations.destroy', $invitation))
        ->assertRedirect(route('invitations.index'));
    $this->get(route('invitations.index'))->assertInertia(fn (Assert $page) => $page->has('invitations', 0));

    auth()->logout();
    $amina = User::factory()->create(['email' => 'amina@example.test']);
    $this->actingAs($amina)->get($link)->assertInertia(fn (Assert $page) => $page
        ->where('refusal', fn (string $refusal) => str_contains($refusal, 'withdrawn')));
    $this->post($link)->assertRedirect($link);
    expect(Tenancy::hasMembership($amina))->toBeFalse();
});

test('an unknown invitation link is not found', function () {
    [, $organization] = organizationWithOwner();

    $this->get(route('invitations.show', ['organization' => $organization->id, 'token' => str_repeat('x', 48)]))->assertNotFound();
    $this->get(route('invitations.show', ['organization' => 'not-an-organization', 'token' => str_repeat('x', 48)]))->assertNotFound();
});

test('an owner can grant all properties to owners and group operations managers', function () {
    $t = twoTenants();
    $link = invitationLink($t->a->owner, ['email' => 'gm@example.test', 'role' => 'group_operations_manager', 'all_properties' => true]);
    $manager = User::factory()->create(['email' => 'gm@example.test']);

    $this->actingAs($manager)->post($link)->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('tenant.organization.id', $t->a->organization->id)
        ->has('tenant.properties', 2));
});

test('only owners and group operations managers can be granted all properties', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('invitations.store'), ['email' => 'amina@example.test', 'role' => 'front_desk', 'all_properties' => true])
        ->assertSessionHasErrors('all_properties');
});

test('an invitation names at least one property of the organization, unless it grants all properties', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('invitations.store'), ['email' => 'amina@example.test', 'role' => 'front_desk', 'all_properties' => false])
        ->assertSessionHasErrors('property_ids');

    $this->actingAs($t->a->owner)
        ->post(route('invitations.store'), ['email' => 'amina@example.test', 'role' => 'front_desk', 'property_ids' => [$t->b->property->id]])
        ->assertSessionHasErrors('property_ids');
});

test('a property manager invites staff to their own property', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    $this->actingAs($manager)->get(route('invitations.create'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('properties', [['value' => $t->a->property->id, 'label' => $t->a->property->name]])
            ->where('roles', fn ($roles) => collect($roles)->pluck('value')->doesntContain('owner')
                && collect($roles)->pluck('value')->doesntContain('group_operations_manager')
                && collect($roles)->every(fn ($role) => $role['mayHaveAllProperties'] === false)));

    $this->post(route('invitations.store'), ['email' => 'amina@example.test', 'role' => 'housekeeping', 'property_ids' => [$t->a->property->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('invitations.index'));

    $this->get(route('invitations.index'))->assertInertia(fn (Assert $page) => $page
        ->has('invitations', 1)
        ->where('invitations.0.canRevoke', true));
});

test('a property manager can\'t invite to another property, grant all properties or invite organization-level roles', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager);

    $this->post(route('invitations.store'), ['email' => 'a@example.test', 'role' => 'front_desk', 'property_ids' => [$t->a->otherProperty->id]])
        ->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'b@example.test', 'role' => 'front_desk', 'property_ids' => [$t->a->property->id, $t->a->otherProperty->id]])
        ->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'c@example.test', 'role' => 'group_operations_manager', 'all_properties' => true])
        ->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'd@example.test', 'role' => 'owner', 'property_ids' => [$t->a->property->id]])
        ->assertForbidden();

    $this->get(route('invitations.index'))->assertInertia(fn (Assert $page) => $page->has('invitations', 0));
});

test('a property manager neither sees nor revokes invitations beyond their own property', function () {
    $t = twoTenants();
    invitationLink($t->a->owner, ['email' => 'amina@example.test', 'property_ids' => [$t->a->otherProperty->id]]);
    $invitation = inTenant($t->a->organization, fn () => Invitation::query()->sole());

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('invitations.index'))
        ->assertInertia(fn (Assert $page) => $page->has('invitations', 0));
    $this->delete(route('invitations.destroy', $invitation))->assertForbidden();

    expect(inTenant($t->a->organization, fn () => Invitation::query()->pending()->count()))->toBe(1);
});

test('members without the invite permission can\'t see or send invitations', function (OrganizationRole $role) {
    $t = twoTenants();
    $this->actingAs($t->a->member($role));

    $this->get(route('invitations.index'))->assertForbidden();
    $this->get(route('invitations.create'))->assertForbidden();
    $this->post(route('invitations.store'), ['email' => 'a@example.test', 'role' => 'front_desk', 'property_ids' => [$t->a->property->id]])
        ->assertForbidden();
})->with([
    OrganizationRole::GroupOperationsManager,
    OrganizationRole::FrontDesk,
    OrganizationRole::Finance,
    OrganizationRole::Housekeeping,
    OrganizationRole::Auditor,
]);

test('a person who is already a member, or already invited, isn\'t invited again', function () {
    $t = twoTenants();
    invitationLink($t->a->owner, ['email' => 'amina@example.test', 'property_ids' => [$t->a->property->id]]);

    $this->actingAs($t->a->owner)
        ->post(route('invitations.store'), ['email' => 'AMINA@example.test', 'role' => 'front_desk', 'property_ids' => [$t->a->property->id]])
        ->assertSessionHasErrors(['email' => 'This person already has a pending invitation. Revoke it to send a new one.']);
    $this->post(route('invitations.store'), ['email' => $t->a->member(OrganizationRole::Finance)->email, 'role' => 'front_desk', 'property_ids' => [$t->a->property->id]])
        ->assertSessionHasErrors(['email' => 'This person is already a member of the organization.']);
});

test('sending, accepting and revoking invitations are audited with the actor and organization', function () {
    [$owner, $organization, $property] = organizationWithOwner();
    $link = invitationLink($owner, ['email' => 'amina@example.test', 'role' => 'auditor', 'property_ids' => [$property->id]]);
    $amina = User::factory()->create(['email' => 'amina@example.test']);
    $this->actingAs($amina)->post($link);

    auth()->logout();
    invitationLink($owner, ['email' => 'zawadi@example.test', 'property_ids' => [$property->id]]);
    $revoked = inTenant($organization, fn () => Invitation::query()->where('email', 'zawadi@example.test')->sole());
    $this->actingAs($owner)->delete(route('invitations.destroy', $revoked));

    $entry = fn (string $event, string $email) => Activity::query()->where('log_name', 'audit')->where('event', $event)->get()
        ->sole(fn (Activity $a) => $a->getExtraProperty('attributes')['email'] === $email);

    $invited = $entry('invited', 'amina@example.test');
    expect($invited->causer?->is($owner))->toBeTrue()
        ->and($invited->getExtraProperty('organization_id'))->toBe($organization->id)
        ->and($invited->getExtraProperty('attributes'))->toMatchArray(['role' => 'auditor', 'all_properties' => false, 'property_ids' => [$property->id]]);

    $accepted = $entry('accepted', 'amina@example.test');
    expect($accepted->causer?->is($amina))->toBeTrue()
        ->and($accepted->getExtraProperty('organization_id'))->toBe($organization->id);

    $revokedEntry = $entry('revoked', 'zawadi@example.test');
    expect($revokedEntry->causer?->is($owner))->toBeTrue()
        ->and($revokedEntry->getExtraProperty('organization_id'))->toBe($organization->id);

    expect(Activity::query()->where('event', 'member_added')->sole()->getExtraProperty('attributes'))
        ->toMatchArray(['user_id' => $amina->id, 'role' => 'auditor']);
});
