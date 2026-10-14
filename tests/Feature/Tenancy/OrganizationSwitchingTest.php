<?php

use App\Domains\Tenancy\Actions\SendInvitation;
use App\Domains\Tenancy\Data\InvitationDetails;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Invitation;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\PropertyForm;
use Tests\Support\TenantFixture;

/*
 * A person in several organizations (through separate invitations) switches
 * between them from the sidebar. Every test uses a member of both fixture
 * organizations, Alpha and Bravo.
 */

/**
 * A pending invitation to the tenant's first property, sent by its owner.
 */
function pendingInvitationIn(TenantFixture $tenant, string $email): Invitation
{
    return inTenant($tenant->organization, fn () => app(SendInvitation::class)->handle($tenant->owner, InvitationDetails::fromArray([
        'email' => $email,
        'role' => 'front_desk',
        'property_ids' => [$tenant->property->id],
    ])));
}

test('the organization switcher lists only the organizations the user belongs to', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::GroupOperationsManager, OrganizationRole::FrontDesk);

    $this->actingAs($dual)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organizations', [
                ['id' => $t->a->organization->id, 'name' => $t->a->organization->name],
                ['id' => $t->b->organization->id, 'name' => $t->b->organization->name],
            ]));

    $this->actingAs($t->a->owner)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organizations', [
                ['id' => $t->a->organization->id, 'name' => $t->a->organization->name],
            ]));
});

test('switching organization makes it and one of its permitted properties current, and pages, lists and permissions follow', function () {
    $t = twoTenants();
    // Runs every Alpha property; manages one Bravo property.
    $dual = $t->memberOfBoth(OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager);
    pendingInvitationIn($t->a, 'newcomer@alpha.test');
    pendingInvitationIn($t->b, 'newcomer@bravo.test');

    $this->actingAs($dual)
        ->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $t->a->organization->id)
            ->where('properties', fn ($properties) => collect($properties)->pluck('id')->sort()->values()->all()
                === collect([$t->a->property->id, $t->a->otherProperty->id])->sort()->values()->all()));
    $this->get(route('invitations.index'))->assertForbidden();

    $this->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization', ['id' => $t->b->organization->id, 'name' => $t->b->organization->name])
            ->where('tenant.property.id', $t->b->property->id)
            ->where('tenant.properties', [['id' => $t->b->property->id, 'name' => $t->b->property->name, 'demo' => false]])
            // The Bravo role's permissions (property manager), not the Alpha one's.
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('members.invite')
                && ! collect($permissions)->contains('properties.manage')));

    $this->get(route('invitations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('invitations', fn ($invitations) => collect($invitations)->pluck('email')->all() === ['newcomer@bravo.test']));
    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)
            ->where('properties', fn ($properties) => collect($properties)->pluck('id')->all() === [$t->b->property->id]));
    // Alpha's records are out of reach while Bravo is current.
    $this->get(route('properties.edit', $t->a->property->id))->assertNotFound();

    $this->put(route('current-organization.update'), ['organization_id' => $t->a->organization->id])
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $t->a->organization->id)
            ->where('tenant.property.id', fn ($id) => in_array($id, [$t->a->property->id, $t->a->otherProperty->id], true))
            ->has('tenant.properties', 2)
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('properties.manage')
                && ! collect($permissions)->contains('members.invite')));
    $this->get(route('invitations.index'))->assertForbidden();
});

test('switching rechecks membership, so an organization the user was removed from cannot be switched back to', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::FrontDesk, OrganizationRole::FrontDesk);

    $this->actingAs($dual)
        ->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
        ->assertRedirect(route('dashboard'));
    $this->put(route('current-organization.update'), ['organization_id' => $t->a->organization->id])
        ->assertRedirect(route('dashboard'));

    inTenant($t->b->organization, fn () => OrganizationMembership::query()->where('user_id', $dual->id)->delete());

    $this->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
        ->assertForbidden();
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $t->a->organization->id)
            ->where('tenant.organizations', [['id' => $t->a->organization->id, 'name' => $t->a->organization->name]]));
});

test('a member removed from the organization they are working in is moved out of it on their next request', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::FrontDesk, OrganizationRole::FrontDesk);

    $this->actingAs($dual)
        ->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id]);

    inTenant($t->b->organization, fn () => OrganizationMembership::query()->where('user_id', $dual->id)->delete());

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.organization.id', $t->a->organization->id)
            ->where('tenant.property.id', $t->a->property->id));
});

test('a user cannot switch to an organization they never belonged to', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
        ->assertForbidden();
    $this->put(route('current-organization.update'), ['organization_id' => 'not-an-organization'])
        ->assertForbidden();
    $this->put(route('current-organization.update'), [])
        ->assertForbidden();

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('tenant.organization.id', $t->a->organization->id));
});

test('after switching organization, the client is told to drop the previous organization\'s pages and filters', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::Owner, OrganizationRole::Owner);

    $this->actingAs($dual)
        ->from(route('properties.index', ['city' => 'Zanzibar City']))
        ->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id])
        // Lands on the dashboard, not back on the previous organization's filtered list.
        ->assertRedirect(route('dashboard'));

    $page = $this->get(route('dashboard'))->viewData('page');

    expect($page['clearHistory'] ?? false)->toBeTrue()
        ->and($page['encryptHistory'] ?? false)->toBeTrue()
        ->and($page['props']['tenant']['organization']['id'])->toBe($t->b->organization->id);
});

test('a form opened in one organization and submitted after switching to another is refused, never saved to the new one', function () {
    $t = twoTenants();
    $dual = $t->memberOfBoth(OrganizationRole::Owner, OrganizationRole::Owner);
    $counts = fn () => [
        inTenant($t->a->organization, fn () => [Property::query()->count(), Invitation::query()->count()]),
        inTenant($t->b->organization, fn () => [Property::query()->count(), Invitation::query()->count()]),
    ];

    // Both forms are opened in Alpha, so they carry an Alpha property; then Bravo is selected (say, in another tab).
    $openedFor = $this->actingAs($dual)->get(route('properties.create'))
        ->assertInertia(fn (Assert $page) => $page->where('tenant.organization.id', $t->a->organization->id))
        ->viewData('page')['props']['tenant']['property']['id'];
    $this->put(route('current-organization.update'), ['organization_id' => $t->b->organization->id]);
    $before = $counts();

    $this->post(route('properties.store'), PropertyForm::payload(['_property' => $openedFor]))
        ->assertForbidden();
    $this->post(route('invitations.store'), [
        '_property' => $openedFor,
        'email' => 'newcomer@example.test',
        'role' => 'group_operations_manager',
        'all_properties' => true,
        'expires_in_days' => 7,
    ])->assertForbidden();

    expect($counts())->toBe($before);
});
