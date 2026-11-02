<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\PropertyForm;
use Tests\Support\TwoTenants;

test('property readers see details and current branding without storage paths or credentials', function () {
    Storage::fake('local');
    $t = twoTenants();
    $this->actingAs($t->a->owner);
    saveBranding($t->a->property, [
        'logo' => UploadedFile::fake()->image('inn.png', 200, 80),
        'accent_colour' => '#1f6f5c',
    ])->assertSessionHasNoErrors();

    $this->get(route('properties.show', $t->a->property))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('properties/show')
        ->where('property.id', $t->a->property->id)
        ->where('property.name', 'Alpha Stone Town Inn')
        ->where('property.propertyType', $t->a->property->property_type->label())
        ->where('property.phone', $t->a->property->phone)
        ->where('property.jurisdictionLabel', 'Zanzibar')
        ->where('property.businessDate', $t->a->property->business_date->toDateString())
        ->where('property.zra_hotel_code', $t->a->property->zra_hotel_code)
        ->where('branding.accent_colour', '#1f6f5c')
        ->where('branding.logo_url', fn ($logo) => str_starts_with($logo, 'data:image/png;base64,'))
        ->where('canUpdate', true)
        ->missing('property.logo_path')
        ->missing('vfms'));

    $this->get(route('properties.show', $t->a->otherProperty))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('property.id', $t->a->otherProperty->id)
        ->missing('property.zra_hotel_code')
        ->where('branding', ['logo_url' => null, 'accent_colour' => null]));
});

test('property details enforce read permissions and property assignments', function (OrganizationRole $role, bool $canView, bool $canUpdate) {
    $t = twoTenants();
    $response = $this->actingAs($t->a->member($role))->get(route('properties.show', $t->a->property));

    if ($canView) {
        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('properties/show')->where('canUpdate', $canUpdate));
    } else {
        $response->assertForbidden();
    }

    $this->get(route('properties.show', $t->a->otherProperty))->assertForbidden();
})->with([
    'property manager' => [OrganizationRole::PropertyManager, true, true],
    'auditor' => [OrganizationRole::Auditor, true, false],
    'front desk' => [OrganizationRole::FrontDesk, false, false],
    'finance' => [OrganizationRole::Finance, false, false],
    'housekeeping' => [OrganizationRole::Housekeeping, false, false],
]);

test('archived property details remain readable but cannot be edited', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => $t->a->otherProperty->forceFill(['archived_at' => now()])->save());

    $this->actingAs($t->a->owner)->get(route('properties.show', $t->a->otherProperty))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('property.id', $t->a->otherProperty->id)
        ->where('property.archivedAt', $t->a->otherProperty->archived_at->toIso8601String())
        ->where('canUpdate', false));
});

test('registration remains optional on setup and existing live property edits', function (PropertyStatus $status) {
    [$owner, , $property] = organizationWithOwner();
    $property->forceFill(['status' => $status])->save();
    $payload = PropertyForm::payload([
        '_property' => $property->id,
        'jurisdiction_profile' => 'zanzibar',
        'tin' => null,
        'z_number' => null,
        'vrn' => null,
        'zra_hotel_code' => null,
        'star_rating' => null,
    ]);

    $this->actingAs($owner)->put(route('properties.update', $property), $payload)->assertSessionHasNoErrors();
    $this->get(route('properties.edit', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.tin', null)->where('property.z_number', null)->where('property.vrn', null)
        ->where('property.zra_hotel_code', null)->where('property.star_rating', null));
    expect($property->fresh()->status)->toBe($status);
})->with([PropertyStatus::Setup, PropertyStatus::Live]);

test('a new Zanzibar property can be saved without registration', function () {
    [$owner, , $property] = organizationWithOwner();
    $this->actingAs($owner)->post(route('properties.store'), PropertyForm::payload([
        '_property' => $property->id, 'jurisdiction_profile' => 'zanzibar',
    ]))->assertSessionHasNoErrors()->assertRedirect(route('properties.index'));

    $this->get(route('properties.index'))->assertInertia(fn (Assert $page) => $page->has('properties', 2));
    $created = inTenant($property, fn () => Property::query()->where('name', 'Serengeti Gate Lodge')->sole());
    expect($created->status)->toBe(PropertyStatus::Setup);
    $this->get(route('properties.edit', $created))->assertInertia(fn (Assert $page) => $page
        ->where('property.tin', null)->where('property.z_number', null)->where('property.vrn', null)
        ->where('property.zra_hotel_code', null)->where('property.star_rating', null));
});

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
    ]))->assertRedirect(route('properties.show', $property));

    $this->get(route('properties.show', $property))->assertInertia(fn (Assert $page) => $page
        ->where('property.name', 'Stone Town Grand')->where('property.currency', 'TZS'));

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
        ->assertRedirect(route('properties.show', $property));

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
    ]))->assertRedirect(route('properties.show', $t->a->otherProperty));

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
    ]))->assertRedirect(route('properties.show', $t->a->property));

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

/** Saves $form as the branding of $property through the settings form, as the signed-in member. */
function saveBranding(Property $property, array $form)
{
    return test()->post(route('properties.branding.update', $property), ['_property' => $property->id, ...$form]);
}

/** The branding the property's settings page shows. */
function brandingShown(Property $property): ?array
{
    return test()->get(route('properties.edit', $property))->assertOk()->viewData('page')['props']['branding'];
}

// protects=#220 branding is per property and reaches its settings page; fails_when=a property shares another's branding or the logo is not shown.
test('an owner gives two properties of one organization their own logo and accent colour', function () {
    Storage::fake('local');
    $t = twoTenants();
    $this->actingAs($t->a->owner);
    expect(brandingShown($t->a->property))->toBe(['logo_url' => null, 'accent_colour' => null]);

    saveBranding($t->a->property, ['logo' => UploadedFile::fake()->image('inn.png', 400, 100), 'accent_colour' => '#1F6F5C'])->assertSessionHasNoErrors()->assertRedirect();
    saveBranding($t->a->otherProperty, ['logo' => UploadedFile::fake()->image('lodge.jpg', 100, 300), 'accent_colour' => '#7a3b10'])->assertSessionHasNoErrors();

    expect(brandingShown($t->a->property))->toMatchArray(['accent_colour' => '#1f6f5c'])
        ->and(brandingShown($t->a->property)['logo_url'])->toStartWith('data:image/png;base64,')
        ->and(brandingShown($t->a->otherProperty))->toMatchArray(['accent_colour' => '#7a3b10'])
        ->and(brandingShown($t->a->otherProperty)['logo_url'])->toStartWith('data:image/jpeg;base64,');
    expect(Activity::query()->where('event', 'branding_updated')->where('subject_id', $t->a->property->id)->exists())->toBeTrue();
});

// protects=#220 branding editors are owners and their own property's manager only; fails_when=a role that edits other settings, or a manager of another property, changes branding.
test('only owners and the property\'s manager edit branding, though group operations managers edit other settings', function () {
    Storage::fake('local');
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $this->actingAs($manager);
    saveBranding($t->a->property, ['accent_colour' => '#123456'])->assertSessionHasNoErrors();
    expect(brandingShown($t->a->property)['accent_colour'])->toBe('#123456');
    $this->get(route('properties.edit', $t->a->property))->assertInertia(fn (Assert $page) => $page->where('canManageBranding', true));
    saveBranding($t->a->otherProperty, ['accent_colour' => '#654321'])->assertForbidden();

    $operations = $t->a->member(OrganizationRole::GroupOperationsManager);
    $this->actingAs($operations)->get(route('properties.edit', $t->a->property))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canManageBranding', false));
    saveBranding($t->a->property, ['accent_colour' => '#000000'])->assertForbidden();
    foreach ([OrganizationRole::FrontDesk, OrganizationRole::Finance, OrganizationRole::Auditor] as $role) {
        $this->actingAs($t->a->member($role));
        saveBranding($t->a->property, ['accent_colour' => '#000000'])->assertForbidden();
    }

    expect($t->a->property->fresh()->accent_colour)->toBe('#123456')->and($t->a->otherProperty->fresh()->accent_colour)->toBeNull()
        // The manager's attempt on another property is refused by property.intent, before the form is read.
        ->and(Activity::query()->where('event', 'refused')->where('properties->action', 'properties.branding.update')->count())->toBe(4);
});

// protects=#220 a branding form saves to the property it was opened for; fails_when=switching property in between moves the branding.
test('a branding form opened for one property never saves to another', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->otherProperty->id])
        ->post(route('properties.branding.update', $t->a->property), ['_property' => $t->a->property->id, 'accent_colour' => '#abcdef'])->assertSessionHasNoErrors();
    $this->post(route('properties.branding.update', $t->a->property), ['accent_colour' => '#abcdef'])->assertSessionHasErrors('_property');

    expect($t->a->property->fresh()->accent_colour)->toBe('#abcdef')->and($t->a->otherProperty->fresh()->accent_colour)->toBeNull();
});

// protects=#220 invalid or active uploads never replace branding; fails_when=a refused save changes or deletes the previous logo or colour.
test('invalid logos and colours are refused and keep the previous branding', function (Closure $form, string $field) {
    Storage::fake('local');
    $t = twoTenants();
    $this->actingAs($t->a->owner);
    saveBranding($t->a->property, ['logo' => UploadedFile::fake()->image('inn.png', 200, 80), 'accent_colour' => '#1f6f5c'])->assertSessionHasNoErrors();
    $before = brandingShown($t->a->property);

    saveBranding($t->a->property, $form())->assertSessionHasErrors($field);

    expect(brandingShown($t->a->property))->toBe($before)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1)
        ->and(Activity::query()->where('event', 'refused')->where('subject_id', $t->a->property->id)->exists())->toBeTrue();
})->with([
    'svg with script' => [fn () => ['logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')], 'logo'],
    'html named png' => [fn () => ['logo' => UploadedFile::fake()->createWithContent('logo.png', '<html><script>alert(1)</script></html>')], 'logo'],
    'pdf' => [fn () => ['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')], 'logo'],
    'too large' => [fn () => ['logo' => UploadedFile::fake()->image('big.png', 200, 80)->size(2048)], 'logo'],
    'colour name' => [fn () => ['accent_colour' => 'red'], 'accent_colour'],
    'short hex' => [fn () => ['accent_colour' => '#fff'], 'accent_colour'],
    'css injection' => [fn () => ['accent_colour' => '#fff;}body{display:none'], 'accent_colour'],
]);

// protects=#220 removal and a storage failure; fails_when=removal leaves the file, or a failed store loses the old logo.
test('an owner removes the logo and clears the colour; a storage failure keeps the previous logo', function () {
    Storage::fake('local');
    $t = twoTenants();
    $this->actingAs($t->a->owner);
    saveBranding($t->a->property, ['logo' => UploadedFile::fake()->image('old.png', 200, 80), 'accent_colour' => '#1f6f5c'])->assertSessionHasNoErrors();
    saveBranding($t->a->property, ['logo' => UploadedFile::fake()->image('new.png', 300, 80), 'accent_colour' => '#1f6f5c'])->assertSessionHasNoErrors();
    expect(Storage::disk('local')->allFiles())->toHaveCount(1);
    $before = brandingShown($t->a->property);

    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('putFileAs')->andReturn(false);
    Storage::set('local', $failing);
    saveBranding($t->a->property, ['logo' => UploadedFile::fake()->image('broken.png', 200, 80)])->assertSessionHasErrors('logo');
    Storage::fake('local');
    expect($t->a->property->fresh()->logo_path)->not->toBeNull();

    saveBranding($t->a->property, ['remove_logo' => '1', 'accent_colour' => ''])->assertSessionHasNoErrors();
    expect(brandingShown($t->a->property))->toBe(['logo_url' => null, 'accent_colour' => null])->and($before['logo_url'])->not->toBeNull();
});
