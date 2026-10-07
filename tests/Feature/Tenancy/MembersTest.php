<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PropertyForm;
use Tests\Support\TenantFixture;

/*
 * Owners manage the people in their organization: roles, properties, removal and
 * ownership transfer (PRD ORG-02). Every change applies on the member's next request.
 */

function membershipOf(TenantFixture $tenant, User $member): OrganizationMembership
{
    return inTenant($tenant->organization, fn () => OrganizationMembership::query()->where('user_id', $member->id)->sole());
}

/**
 * The member with two-factor authentication on, as owners need it for owner-only actions (#13).
 */
function withTwoFactorOn(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user->fresh() ?? $user;
}

test('the members list shows each member\'s role and properties, or all properties', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('members/index')
            ->has('members', count(OrganizationRole::cases()))
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'owner@alpha.test'
                && $m['roleLabel'] === 'Owner' && $m['allProperties'] === true))
            ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'front_desk@alpha.test'
                && $m['roleLabel'] === 'Front desk' && $m['allProperties'] === false && $m['properties'] === ['Alpha Stone Town Inn'])));
});

test('an owner opens a member\'s access form with their current role and properties', function () {
    $t = twoTenants();
    $frontDesk = membershipOf($t->a, $t->a->member(OrganizationRole::FrontDesk));

    $this->actingAs($t->a->owner)
        ->get(route('members.edit', $frontDesk))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('members/edit')
            ->where('member.id', $frontDesk->id)
            ->where('member.email', 'front_desk@alpha.test')
            ->where('member.role', 'front_desk')
            ->where('member.allProperties', false)
            ->where('member.propertyIds', [$t->a->property->id])
            ->where('member.canTransferOwnership', true)
            // Owners are made only by a transfer, so the role choice leaves them out.
            ->where('roles', fn ($roles) => collect($roles)->doesntContain('value', 'owner') && count($roles) === count(OrganizationRole::cases()) - 1)
            ->has('properties', 2));

    $this->actingAs($t->a->member(OrganizationRole::GroupOperationsManager))
        ->get(route('members.edit', $frontDesk))
        ->assertForbidden();
});

test('removing a member clears directly granted organization permissions', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);
    inTenant($t->a->organization, fn () => $manager->givePermissionTo('settings.finance.manage'));

    $this->actingAs($t->a->owner)
        ->delete(route('members.destroy', membershipOf($t->a, $manager)), ['_property' => $t->a->property->id])
        ->assertSessionHasNoErrors();

    expect(inTenant($t->a->organization, fn () => $manager->fresh()->hasPermissionTo('settings.finance.manage')))->toBeFalse();
});

test('changing a member\'s role clears directly granted organization permissions', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);
    inTenant($t->a->organization, fn () => $manager->givePermissionTo('settings.finance.manage'));

    $this->actingAs($t->a->owner)
        ->put(route('members.update', membershipOf($t->a, $manager)), [
            '_property' => $t->a->property->id,
            'role' => 'front_desk',
            'all_properties' => false,
            'property_ids' => [$t->a->property->id],
        ])
        ->assertSessionHasNoErrors();

    expect(inTenant($t->a->organization, fn () => $manager->fresh()->hasPermissionTo('settings.finance.manage')))->toBeFalse();
});

test('changing a member\'s role and properties applies on their next request', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($frontDesk)->get(route('properties.edit', $t->a->otherProperty))->assertForbidden();

    $this->actingAs($t->a->owner)
        ->put(route('members.update', membershipOf($t->a, $frontDesk)), [
            '_property' => $t->a->property->id,
            'role' => 'property_manager',
            'all_properties' => false,
            'property_ids' => [$t->a->property->id, $t->a->otherProperty->id],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('members.index'));

    $this->actingAs($frontDesk->fresh())->get(route('properties.edit', $t->a->otherProperty))->assertOk();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('properties.update')
            && ! collect($permissions)->contains('guests.identity.view')));
});

test('a member who loses a property is refused it on their next request, including a form already open', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    // The manager opens the edit form for their property...
    $this->actingAs($manager)->get(route('properties.edit', $t->a->property))->assertOk();

    // ...and the owner moves them to the other property.
    $this->actingAs($t->a->owner)
        ->put(route('members.update', membershipOf($t->a, $manager)), [
            '_property' => $t->a->property->id,
            'role' => 'property_manager',
            'all_properties' => false,
            'property_ids' => [$t->a->otherProperty->id],
        ])
        ->assertSessionHasNoErrors();

    $this->actingAs($manager->fresh())
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['name' => 'Renamed', '_property' => $t->a->property->id]))
        ->assertForbidden();
    $this->get(route('properties.edit', $t->a->property))->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('tenant.property.id', $t->a->otherProperty->id)
        ->has('tenant.properties', 1));

    expect($t->a->property->fresh()->name)->not->toBe('Renamed');
});

test('a removed member is refused on their next request, including pages and forms already open', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $membership = membershipOf($t->a, $manager);

    $this->actingAs($manager)->get(route('properties.edit', $t->a->property))->assertOk();

    $this->actingAs($t->a->owner)
        ->delete(route('members.destroy', $membership), ['_property' => $t->a->property->id])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('members.index'));

    $this->actingAs($manager->fresh())
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['name' => 'Renamed', '_property' => $t->a->property->id]))
        ->assertRedirect(route('organizations.create'));
    $this->get(route('properties.edit', $t->a->property))->assertRedirect(route('organizations.create'));
    $this->get(route('dashboard'))->assertRedirect(route('organizations.create'));

    expect($t->a->property->fresh()->name)->not->toBe('Renamed');
    $this->actingAs($t->a->owner)->get(route('members.index'))
        ->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => collect($members)->doesntContain('email', 'property_manager@alpha.test')));
});

test('a member removed from one organization keeps the other, and is refused the one they left', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::PropertyManager, OrganizationRole::FrontDesk);

    $this->actingAs($dual)->put(route('current-organization.update'), ['organization_id' => $t->a->organization->id]);

    $this->actingAs($t->a->owner)
        ->delete(route('members.destroy', membershipOf($t->a, $dual)), ['_property' => $t->a->property->id])
        ->assertSessionHasNoErrors();

    $this->actingAs($dual->fresh())
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['name' => 'Renamed', '_property' => $t->a->property->id]))
        ->assertForbidden();
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('tenant.organization.id', $t->b->organization->id)
        ->has('tenant.organizations', 1));
});

test('removing or demoting the last owner is refused with a clear message', function () {
    $t = twoTenants();
    $owner = membershipOf($t->a, $t->a->owner);

    $this->actingAs($t->a->owner)
        ->delete(route('members.destroy', $owner), ['_property' => $t->a->property->id])
        ->assertSessionHasErrors(['member' => 'The organization needs at least one owner. Transfer ownership to another member first.']);

    $this->actingAs($t->a->owner)
        ->put(route('members.update', $owner), [
            '_property' => $t->a->property->id,
            'role' => 'group_operations_manager',
            'all_properties' => true,
        ])
        ->assertSessionHasErrors(['role' => 'The organization needs at least one owner. Transfer ownership to another member first.']);

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'owner@alpha.test' && $m['role'] === 'owner')));

    expect(Activity::query()->whereIn('event', ['member_removal_refused', 'member_change_refused'])
        ->where('log_name', 'audit')->pluck('event')->all())->toBe(['member_removal_refused', 'member_change_refused'])
        ->and(Activity::query()->whereIn('event', ['member_removal_refused', 'member_change_refused'])
            ->get()->every(fn (Activity $entry) => $entry->getExtraProperty('result') === 'refused'))->toBeTrue();
});

test('no one is made an owner except through a transfer', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->put(route('members.update', membershipOf($t->a, $t->a->member(OrganizationRole::GroupOperationsManager))), [
            '_property' => $t->a->property->id,
            'role' => 'owner',
            'all_properties' => true,
        ])
        ->assertSessionHasErrors(['role' => 'Ownership moves only through a transfer. Use "Transfer ownership" instead.']);

    expect(inTenant($t->a->organization, fn () => $t->a->member(OrganizationRole::GroupOperationsManager)->getRoleNames()->all()))
        ->toBe(['group_operations_manager']);
});

test('transferring ownership makes the target an owner and gives the previous owner the role chosen', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($t->a->owner)
        ->post(route('members.transfer-ownership', membershipOf($t->a, $frontDesk)), [
            '_property' => $t->a->property->id,
            'role' => 'finance',
            'all_properties' => false,
            'property_ids' => [$t->a->otherProperty->id],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('members.index'));

    // The new owner must turn on two-factor authentication before owner-only pages (#13).
    $this->actingAs($frontDesk->fresh())->get(route('members.index'))->assertRedirect(route('security.edit'));

    $this->actingAs(withTwoFactorOn($frontDesk))->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'front_desk@alpha.test'
            && $m['role'] === 'owner' && $m['allProperties'] === true))
        ->where('members', fn ($members) => collect($members)->contains(fn ($m) => $m['email'] === 'owner@alpha.test'
            && $m['role'] === 'finance' && $m['properties'] === ['Alpha Arusha Lodge'])));

    // The previous owner works only as chosen: no more member management.
    $this->actingAs($t->a->owner->fresh())
        ->delete(route('members.destroy', membershipOf($t->a, $frontDesk)), ['_property' => $t->a->otherProperty->id])
        ->assertForbidden();
});

test('a previous owner who stays an owner can then be demoted, since another owner remains', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);

    $this->actingAs($t->a->owner)
        ->post(route('members.transfer-ownership', membershipOf($t->a, $manager)), [
            '_property' => $t->a->property->id,
            'role' => 'owner',
            'all_properties' => true,
        ])
        ->assertSessionHasNoErrors();

    // A new owner must turn on two-factor authentication before owner-only actions (#13).
    $demote = [
        '_property' => $t->a->property->id,
        'role' => 'auditor',
        'all_properties' => false,
        'property_ids' => [$t->a->property->id],
    ];
    $this->actingAs($manager->fresh())
        ->put(route('members.update', membershipOf($t->a, $t->a->owner)), $demote)
        ->assertRedirect(route('security.edit'));

    $this->actingAs(withTwoFactorOn($manager))
        ->put(route('members.update', membershipOf($t->a, $t->a->owner)), [
            '_property' => $t->a->property->id,
            'role' => 'auditor',
            'all_properties' => false,
            'property_ids' => [$t->a->property->id],
        ])
        ->assertSessionHasNoErrors();

    $this->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->where('role', 'owner')->pluck('email')->all() === ['group_operations_manager@alpha.test']));
});

test('ownership cannot be transferred to an owner, to yourself, or by someone who is not an owner', function () {
    $t = twoTenants();
    $payload = ['_property' => $t->a->property->id, 'role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->property->id]];

    $this->actingAs($t->a->owner)
        ->post(route('members.transfer-ownership', membershipOf($t->a, $t->a->owner)), $payload)
        ->assertSessionHasErrors(['member' => 'You already own this organization. Choose another member.']);

    $this->actingAs($t->a->member(OrganizationRole::GroupOperationsManager))
        ->post(route('members.transfer-ownership', membershipOf($t->a, $t->a->member(OrganizationRole::FrontDesk))), $payload)
        ->assertForbidden();

    $this->actingAs($t->a->owner)->get(route('members.index'))->assertInertia(fn (Assert $page) => $page
        ->where('members', fn ($members) => collect($members)->where('role', 'owner')->pluck('email')->all() === ['owner@alpha.test']));
});

test('only owners change, remove or see the controls for other members', function (OrganizationRole $role) {
    $t = twoTenants();
    $member = $t->a->member($role);
    $frontDesk = membershipOf($t->a, $t->a->member(OrganizationRole::FrontDesk));
    $grant = ['_property' => $t->a->property->id, 'role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->property->id]];

    $this->actingAs($member)->put(route('members.update', $frontDesk), $grant)->assertForbidden();
    $this->actingAs($member)->delete(route('members.destroy', $frontDesk), ['_property' => $t->a->property->id])->assertForbidden();

    expect($frontDesk->grant()->role)->toBe(OrganizationRole::FrontDesk);
})->with(array_values(array_filter(OrganizationRole::cases(), fn (OrganizationRole $role) => $role !== OrganizationRole::Owner)));

test('members with members.view see the members of their own properties; others are refused the list', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('members.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->every(fn ($m) => $m['canManage'] === false)));

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('members.index'))->assertForbidden();
    $this->actingAs($t->a->member(OrganizationRole::Housekeeping))->get(route('members.index'))->assertForbidden();
});

test('a property manager does not see members who work only in other properties', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($t->a->owner)->put(route('members.update', membershipOf($t->a, $frontDesk)), [
        '_property' => $t->a->property->id,
        'role' => 'front_desk',
        'all_properties' => false,
        'property_ids' => [$t->a->otherProperty->id],
    ])->assertSessionHasNoErrors();

    $this->actingAs($t->a->member(OrganizationRole::PropertyManager))
        ->get(route('members.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('members', fn ($members) => collect($members)->doesntContain('email', 'front_desk@alpha.test')
                && collect($members)->contains('email', 'owner@alpha.test')));
});

test('role changes, property changes, removals and transfers are audited with before and after values', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    $housekeeping = $t->a->member(OrganizationRole::Housekeeping);
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);
    $form = ['_property' => $t->a->property->id];

    $this->actingAs($t->a->owner)->put(route('members.update', membershipOf($t->a, $frontDesk)), [...$form,
        'role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->otherProperty->id],
    ])->assertSessionHasNoErrors();
    $this->delete(route('members.destroy', membershipOf($t->a, $housekeeping)), $form)->assertSessionHasNoErrors();
    $this->post(route('members.transfer-ownership', membershipOf($t->a, $manager)), [...$form,
        'role' => 'group_operations_manager', 'all_properties' => true,
    ])->assertSessionHasNoErrors();

    $entries = Activity::query()->where('log_name', 'audit')->whereIn('event', ['member_updated', 'member_removed', 'ownership_transferred'])
        ->orderBy('id')->get();

    expect($entries->pluck('event')->all())->toBe(['member_updated', 'member_removed', 'ownership_transferred'])
        ->and($entries->every(fn (Activity $entry) => $entry->getExtraProperty('result') === 'success'))->toBeTrue()
        ->and($entries->every(fn (Activity $entry) => $entry->causer_id === $t->a->owner->id
            && $entry->getExtraProperty('organization_id') === $t->a->organization->id))->toBeTrue();

    expect($entries[0]->getExtraProperty('old'))->toEqual(['user_id' => $frontDesk->id, 'role' => 'front_desk', 'all_properties' => false, 'property_ids' => [$t->a->property->id]])
        ->and($entries[0]->getExtraProperty('attributes'))->toEqual(['user_id' => $frontDesk->id, 'role' => 'auditor', 'all_properties' => false, 'property_ids' => [$t->a->otherProperty->id]]);

    expect($entries[1]->getExtraProperty('old'))->toEqual(['user_id' => $housekeeping->id, 'role' => 'housekeeping', 'all_properties' => false, 'property_ids' => [$t->a->property->id]])
        ->and($entries[1]->getExtraProperty('attributes'))->toBeNull();

    expect($entries[2]->getExtraProperty('old'))->toEqual([
        'new_owner' => ['user_id' => $manager->id, 'role' => 'group_operations_manager', 'all_properties' => true, 'property_ids' => []],
        'previous_owner' => ['user_id' => $t->a->owner->id, 'role' => 'owner', 'all_properties' => true, 'property_ids' => []],
    ])->and($entries[2]->getExtraProperty('attributes'))->toEqual([
        'new_owner' => ['user_id' => $manager->id, 'role' => 'owner', 'all_properties' => true, 'property_ids' => []],
        'previous_owner' => ['user_id' => $t->a->owner->id, 'role' => 'group_operations_manager', 'all_properties' => true, 'property_ids' => []],
    ]);
});
