<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\PropertyMembership;
use Inertia\Testing\AssertableInertia as Assert;

test('the property switcher lists only the properties the member may act in', function (OrganizationRole $role, int $count) {
    $t = twoTenants();

    $this->actingAs($t->a->member($role))
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenant.properties', $count)
            ->where('tenant.properties', fn ($properties) => collect($properties)->pluck('id')
                ->diff(collect($t->a->properties)->pluck('id'))->isEmpty()));
})->with([
    'owner' => [OrganizationRole::Owner, 2],
    'group operations manager' => [OrganizationRole::GroupOperationsManager, 2],
    'front desk' => [OrganizationRole::FrontDesk, 1],
]);

test('a member with several properties switches property, and the current property updates everywhere', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner);

    foreach ([$t->a->property, $t->a->otherProperty, $t->a->property] as $property) {
        $this->put(route('current-property.update'), ['property_id' => $property->id])
            ->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.organization.id', $t->a->organization->id)
                ->where('tenant.property.id', $property->id)
                ->where('tenant.property.name', $property->name));

        $this->get(route('properties.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tenant.property.id', $property->id));
    }
});

test('a member cannot switch to a property they may not act in', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($frontDesk)
        ->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])
        ->assertForbidden();
    $this->put(route('current-property.update'), ['property_id' => $t->b->property->id])
        ->assertForbidden();
    $this->put(route('current-property.update'), ['property_id' => 'not-a-property'])
        ->assertForbidden();

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('tenant.property.id', $t->a->property->id));
});

test('switching rechecks membership, so a revoked property cannot be switched back to', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    inTenant($t->a->organization, fn () => PropertyMembership::query()->create([
        'user_id' => $frontDesk->id,
        'property_id' => $t->a->otherProperty->id,
    ]));

    $this->actingAs($frontDesk)
        ->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])
        ->assertRedirect(route('dashboard'));
    $this->put(route('current-property.update'), ['property_id' => $t->a->property->id])
        ->assertRedirect(route('dashboard'));

    inTenant($t->a->otherProperty, fn () => PropertyMembership::query()
        ->where('user_id', $frontDesk->id)
        ->where('property_id', $t->a->otherProperty->id)
        ->delete());

    $this->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])
        ->assertForbidden();
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.id', $t->a->property->id)
            ->has('tenant.properties', 1));
});

test('after switching, the client is told to drop the previous property\'s pages and filters', function () {
    $t = twoTenants();

    // A list page with filters from the previous property.
    $this->actingAs($t->a->owner)
        ->from(route('properties.index', ['city' => 'Zanzibar City']))
        ->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])
        // Lands on the dashboard, not back on the filtered list.
        ->assertRedirect(route('dashboard'));

    $page = $this->get(route('dashboard'))->viewData('page');

    // Browser history from the previous property can no longer be restored.
    expect($page['clearHistory'] ?? false)->toBeTrue()
        ->and($page['encryptHistory'] ?? false)->toBeTrue();

    // Only the switch clears history; ordinary visits keep it.
    expect($this->get(route('dashboard'))->viewData('page')['clearHistory'] ?? false)->toBeFalse();
});
