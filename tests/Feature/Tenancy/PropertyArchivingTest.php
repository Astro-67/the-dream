<?php

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Contracts\PreArchiveCheck;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Support\Tenancy\Access;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Tenancy\RefuseArchivingProperty;
use Tests\Support\PropertyForm;

afterEach(function () {
    RefuseArchivingProperty::$refusedPropertyId = null;
});

test('active property query scope excludes archived properties', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty));

    expect(inTenant($t->a->organization, fn () => Property::query()->active()->pluck('id')->all()))
        ->toBe([$t->a->property->id]);
});

test('an owner archives a property, which leaves the switcher and the list of active properties', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'));

    $this->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('properties/index')
            ->where('tenant.properties', [['id' => $t->a->property->id, 'name' => 'Alpha Stone Town Inn', 'demo' => false]])
            ->has('properties', 1)
            ->where('properties.0.id', $t->a->property->id)
            ->has('archivedProperties', 1)
            ->where('archivedProperties.0.id', $t->a->otherProperty->id)
            ->where('archivedProperties.0.name', 'Alpha Arusha Lodge')
            ->where('properties.0.can.archive', true)
            ->where('archivedProperties.0.can.restore', true));
});

test('archiving the property a member is working in moves them to another active property', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'));

    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.id', $t->a->otherProperty->id)
            ->has('tenant.properties', 1));

    // A form opened for the archived property can no longer be saved to it.
    $this->put(route('properties.update', $t->a->otherProperty), PropertyForm::payload(['_property' => $t->a->property->id]))
        ->assertForbidden();
});

test('an owner restores an archived property, which returns to the switcher', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id]);

    $this->delete(route('properties.restore', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'));

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenant.properties', 2)
            ->has('properties', 2)
            ->has('archivedProperties', 0));

    $this->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])
        ->assertRedirect(route('dashboard'));
});

test('archiving the organization\'s last active property is refused', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id]);

    $this->from(route('properties.index'))
        ->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'))
        ->assertSessionHasErrors(['archive' => 'Alpha Stone Town Inn is the organization\'s only active property. Add or restore another property before archiving it.']);

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.id', $t->a->property->id)
            ->has('properties', 1)
            ->where('properties.0.id', $t->a->property->id));
});

test('a registered pre-archive check that fails blocks archiving and shows its reason', function () {
    $t = twoTenants();
    RefuseArchivingProperty::$refusedPropertyId = $t->a->otherProperty->id;
    app()->tag([RefuseArchivingProperty::class], PreArchiveCheck::TAG);

    $this->actingAs($t->a->owner)
        ->from(route('properties.index'))
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'))
        ->assertSessionHasErrors(['archive' => 'This property has future bookings.']);

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenant.properties', 2)
            ->has('archivedProperties', 0));
    expect(Activity::query()->where('event', 'archived')->exists())->toBeFalse()
        ->and(Activity::query()->where('event', 'archive_refused')->where('subject_id', $t->a->otherProperty->id)->first()?->getExtraProperty('result'))->toBe('refused');
});

test('a property every pre-archive check passes is archived', function () {
    $t = twoTenants();
    RefuseArchivingProperty::$refusedPropertyId = $t->a->property->id;
    app()->tag([RefuseArchivingProperty::class], PreArchiveCheck::TAG);

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertRedirect(route('properties.index'))
        ->assertSessionHasNoErrors();

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page->has('archivedProperties', 1));
});

test('archiving and restoring are audited with the actor, and the property\'s earlier history is kept', function () {
    $t = twoTenants();
    $property = $t->a->otherProperty;

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $property), PropertyForm::payload(['_property' => $property->id, 'name' => 'Alpha Arusha Lodge']));
    $this->post(route('properties.archive', $property), ['_property' => $t->a->property->id]);
    $this->delete(route('properties.restore', $property), ['_property' => $t->a->property->id]);

    $history = Activity::query()->where('subject_id', $property->id)->orderBy('id')->get();

    expect($history->pluck('event')->all())->toBe(['created', 'updated', 'archived', 'restored'])
        ->and($history[2]->getExtraProperty('result'))->toBe('success')
        ->and($history[2]->getExtraProperty('old'))->toBe(['archived_at' => null])
        ->and($history[2]->getExtraProperty('attributes.archived_at'))->toBeString()
        ->and($history[3]->getExtraProperty('result'))->toBe('success')
        ->and($history[3]->getExtraProperty('old.archived_at'))->toBeString()
        ->and($history[3]->getExtraProperty('attributes'))->toBe(['archived_at' => null]);

    foreach ($history->where('event', '!=', 'created') as $entry) {
        expect($entry->causer?->is($t->a->owner))->toBeTrue()
            ->and($entry->getExtraProperty('organization_id'))->toBe($t->a->organization->id)
            ->and($entry->getExtraProperty('property_id'))->toBe($property->id);
    }
});

test('permitted readers can access archived records while writes remain refused', function () {
    $t = twoTenants();
    $auditor = $t->a->member(OrganizationRole::Auditor);

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id]);

    $this->actingAs($auditor)
        ->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('archivedProperties', 1)
            ->where('archivedProperties.0.name', 'Alpha Stone Town Inn'));

    expect(inTenant($t->a->organization, fn () => Access::allows($auditor, OrganizationPermission::PropertiesView, $t->a->property)))->toBeTrue();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->otherProperty->id]);

    $this->actingAs($auditor)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->otherProperty->id]))
        ->assertForbidden();
});

test('managers still see archived properties, which stay out of reach for daily work', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::GroupOperationsManager);

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id]);

    $this->actingAs($manager)
        ->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('tenant.properties', 1)
            ->has('archivedProperties', 1)
            ->where('archivedProperties.0.name', 'Alpha Arusha Lodge')
            ->where('archivedProperties.0.can.restore', false));

    $this->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id])->assertForbidden();
});

test('only owners archive and restore properties', function (OrganizationRole $role) {
    $t = twoTenants();
    $member = $t->a->member($role);

    $this->actingAs($member)
        ->post(route('properties.archive', $t->a->property), ['_property' => $t->a->property->id])
        ->assertForbidden();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id]);

    $this->actingAs($member)
        ->delete(route('properties.restore', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertForbidden();

    $this->actingAs($t->a->owner)->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties', 1)
            ->where('properties.0.id', $t->a->property->id)
            ->has('archivedProperties', 1));
})->with([
    'group operations manager' => OrganizationRole::GroupOperationsManager,
    'property manager' => OrganizationRole::PropertyManager,
    'front desk' => OrganizationRole::FrontDesk,
    'finance' => OrganizationRole::Finance,
    'housekeeping' => OrganizationRole::Housekeeping,
    'auditor' => OrganizationRole::Auditor,
]);

test('members with properties.view can see the organization\'s archived list', function (OrganizationRole $role) {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->post(route('properties.archive', $t->a->otherProperty), ['_property' => $t->a->property->id]);

    $this->actingAs($t->a->member($role))
        ->get(route('properties.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('properties', 1)
            ->where('properties.0.can.archive', false)
            ->has('archivedProperties', 1));
})->with([
    'property manager' => OrganizationRole::PropertyManager,
    'auditor' => OrganizationRole::Auditor,
]);
