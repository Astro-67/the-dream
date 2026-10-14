<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

test('an owner adds a property that keeps its own timezone, currency and jurisdiction profile', function () {
    [$owner, , $first] = organizationWithOwner();

    $this->actingAs($owner)
        ->post(route('properties.store'), PropertyForm::payload(['_property' => $first->id]))
        ->assertRedirect(route('properties.index'));

    $this->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('properties/index')
            ->has('properties', 2)
            ->where('properties.0.name', 'Serengeti Gate Lodge')
            ->where('properties.0.timezone', 'Africa/Nairobi')
            ->where('properties.0.currency', 'EUR')
            ->where('properties.0.jurisdictionProfile', 'mainland_tanzania')
            ->where('properties.1.name', 'Stone Town Inn')
            ->where('properties.1.timezone', 'Africa/Dar_es_Salaam')
            ->where('properties.1.currency', 'USD')
            ->where('properties.1.jurisdictionProfile', 'zanzibar')
        );
});

test('the property form must carry the property it was opened for', function () {
    [$owner] = organizationWithOwner();

    $this->actingAs($owner)
        ->post(route('properties.store'), PropertyForm::payload())
        ->assertSessionHasErrors('_property');

    $this->get(route('properties.index'))->assertInertia(fn (Assert $page) => $page->has('properties', 1));
});

test('an owner edits a property\'s details', function () {
    [$owner, , $property] = organizationWithOwner();

    $this->actingAs($owner)
        ->get(route('properties.edit', $property))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('properties/edit')
            ->where('property.id', $property->id)
            ->where('property.name', 'Stone Town Inn')
            ->where('property.zra_hotel_code', 'ZH-0042'));

    $this->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id,
        'name' => 'Stone Town Grand',
        'currency' => 'TZS',
        'jurisdiction_profile' => 'zanzibar',
        'zra_hotel_code' => 'ZH-0099',
    ]))->assertRedirect(route('properties.index'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.name', 'Stone Town Grand')
            ->where('tenant.property.currency', 'TZS')
            ->where('tenant.property.timezone', 'Africa/Nairobi')
            ->where('tenant.property.zraHotelCode', 'ZH-0099'));
});

test('property creation and edits are audited with the actor and the before and after values', function () {
    [$owner, $organization, $property] = organizationWithOwner();

    $this->actingAs($owner)->post(route('properties.store'), PropertyForm::payload(['_property' => $property->id]));
    $this->put(route('properties.update', $property), PropertyForm::payload([
        '_property' => $property->id,
        'name' => 'Stone Town Grand',
        'jurisdiction_profile' => 'zanzibar',
        'zra_hotel_code' => 'ZH-0042',
    ]));

    $created = Activity::query()->where('event', 'created')->where('description', 'Property created')->latest('id')->first();
    $updated = Activity::query()->where('event', 'updated')->where('subject_id', $property->id)->sole();
    expect($created->getExtraProperty('result'))->toBe('success')
        ->and($updated->getExtraProperty('result'))->toBe('success')
        ->and($created->causer?->is($owner))->toBeTrue()
        ->and($created->getExtraProperty('organization_id'))->toBe($organization->id)
        ->and($created->getExtraProperty('attributes')['name'])->toBe('Serengeti Gate Lodge');

    expect($updated->causer?->is($owner))->toBeTrue()
        ->and($updated->getExtraProperty('organization_id'))->toBe($organization->id)
        ->and($updated->getExtraProperty('property_id'))->toBe($property->id)
        ->and($updated->getExtraProperty('old'))->toMatchArray([
            'name' => 'Stone Town Inn',
            'currency' => 'USD',
            'timezone' => 'Africa/Dar_es_Salaam',
        ])
        ->and($updated->getExtraProperty('attributes'))->toMatchArray([
            'name' => 'Stone Town Grand',
            'currency' => 'EUR',
            'timezone' => 'Africa/Nairobi',
        ])
        // Unchanged fields are left out.
        ->and($updated->getExtraProperty('old'))->not->toHaveKey('zra_hotel_code');
});

test('a mainland property\'s edit form is never sent Zanzibar-only fields', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->get(route('properties.edit', $t->a->otherProperty))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('property.jurisdiction_profile', 'mainland_tanzania')
            ->missing('property.zra_hotel_code'));
});

test('moving a property to mainland Tanzania drops its Zanzibar-only fields', function () {
    [$owner, , $property] = organizationWithOwner();

    $this->actingAs($owner)
        ->put(route('properties.update', $property), PropertyForm::payload([
            '_property' => $property->id,
            'jurisdiction_profile' => 'mainland_tanzania',
            'zra_hotel_code' => 'ZH-0042',
        ]))
        ->assertRedirect(route('properties.index'));

    $this->get(route('properties.edit', $property))
        ->assertInertia(fn (Assert $page) => $page->missing('property.zra_hotel_code'));
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.jurisdictionProfile', 'mainland_tanzania')
            ->missing('tenant.property.zraHotelCode'));
});

test('a mainland property configures the registration fields check-in requires', function () {
    $t = twoTenants();
    $property = $t->a->otherProperty;
    $this->actingAs($t->a->owner);
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page->where('property.registration_fields', [])->has('registrationFieldOptions', 5));
    $this->put(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'registration_fields' => ['type', 'number']]))->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page->where('property.registration_fields', ['type', 'number']));
    $this->putJson(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'registration_fields' => ['shoe_size']]))->assertJsonValidationErrors('registration_fields.0');
});

test('a Zanzibar property never stores or is sent configurable registration fields', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner);
    $this->put(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'jurisdiction_profile' => 'zanzibar', 'registration_fields' => ['expiry']]))->assertSessionHasNoErrors();
    expect($property->fresh()->registration_fields)->toBeNull();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page->missing('property.registration_fields'));
});

test('a group operations manager adds and edits properties', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);

    $this->actingAs($manager)
        ->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertRedirect(route('properties.index'));

    $this->put(route('properties.update', $t->a->otherProperty), PropertyForm::payload([
        '_property' => $t->a->otherProperty->id,
        'name' => 'Alpha Arusha Lodge Renamed',
    ]))->assertRedirect(route('properties.index'));

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties', 3)
            ->where('properties', fn ($properties) => collect($properties)->pluck('name')->contains('Alpha Arusha Lodge Renamed')));
});

test('members whose role has no property permissions can neither see, add nor edit properties', function (OrganizationRole $role) {
    $t = twoTenants();
    $member = $t->a->member($role);

    $this->actingAs($member)->get(route('properties.index'))->assertForbidden();
    $this->get(route('properties.create'))->assertForbidden();
    $this->get(route('properties.edit', $t->a->property))->assertForbidden();
    $this->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))->assertForbidden();
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id,
        'name' => 'Hijacked',
    ]))->assertForbidden();

    expect(propertyNames($t))->toBe(['Alpha Arusha Lodge', 'Alpha Stone Town Inn']);
})->with([
    'front desk' => OrganizationRole::FrontDesk,
    'finance' => OrganizationRole::Finance,
    'housekeeping' => OrganizationRole::Housekeeping,
]);

test('a property manager edits their own property, but can\'t add properties or edit another', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);

    $this->actingAs($manager)
        ->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)
            ->has('properties', 1)
            ->where('properties.0.id', $t->a->property->id)
            ->where('properties.0.can.update', true));

    $this->get(route('properties.edit', $t->a->property))->assertOk();
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id,
        'name' => 'Alpha Stone Town Grand',
    ]))->assertRedirect(route('properties.index'));

    $this->get(route('properties.create'))->assertForbidden();
    $this->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))->assertForbidden();
    // Changing the ID in the URL to the property they don't work in.
    $this->get(route('properties.edit', $t->a->otherProperty))->assertForbidden();
    $this->put(route('properties.update', $t->a->otherProperty), PropertyForm::payload([
        '_property' => $t->a->property->id,
        'name' => 'Hijacked',
    ]))->assertForbidden();

    expect(propertyNames($t))->toBe(['Alpha Arusha Lodge', 'Alpha Stone Town Grand']);
});

test('an auditor sees the properties they may view, but every change is refused', function () {
    $t = twoTenants();

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', false)
            ->has('properties', 1)
            ->where('properties.0.name', 'Alpha Stone Town Inn')
            ->where('properties.0.can.update', false));

    $this->get(route('properties.create'))->assertForbidden();
    $this->get(route('properties.edit', $t->a->property))->assertForbidden();
    $this->post(route('properties.store'), PropertyForm::payload(['_property' => $t->a->property->id]))->assertForbidden();
    $this->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id,
        'name' => 'Hijacked',
    ]))->assertForbidden();

    expect(propertyNames($t))->toBe(['Alpha Arusha Lodge', 'Alpha Stone Town Inn']);
});

test('owners see every property with the actions to add and edit them', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', true)
            ->has('properties', 2)
            ->where('properties.0.can.update', true)
            ->where('properties.1.can.update', true));
});

/**
 * Alpha's property names as stored, sorted.
 *
 * @return list<string>
 */
function propertyNames(TwoTenants $t): array
{
    return inTenant($t->a->organization, fn () => Property::query()->orderBy('name')->pluck('name')->all());
}

test('a property manager sets the close time; members who cannot edit the property cannot', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $this->actingAs($t->a->member($role))->put(route('properties.update', $t->a->property), PropertyForm::payload([
        '_property' => $t->a->property->id,
        'close_time' => '23:30',
    ]))->assertStatus($allowed ? 302 : 403)->assertSessionHasNoErrors();

    expect($t->a->property->fresh()->close_time)->toBe($allowed ? '23:30:00' : '02:00:00');
    if ($allowed) {
        $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('property.close_time', '23:30'));
        $this->putJson(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id, 'close_time' => '25:00']))
            ->assertUnprocessable()->assertJsonValidationErrors('close_time');
    }
})->with([
    'property manager' => [OrganizationRole::PropertyManager, true],
    'front desk' => [OrganizationRole::FrontDesk, false],
    'finance' => [OrganizationRole::Finance, false],
]);
