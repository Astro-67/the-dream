<?php

use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Actions\MarkTaxesReviewed;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\PropertyForm;
use Tests\Support\TenantFixture;

/** Alpha's owner creates a demo property over HTTP; returns it. */
function demoPropertyFor(TenantFixture $tenant): Property
{
    test()->actingAs($tenant->owner)
        ->post(route('properties.demo.store'), ['_property' => $tenant->property->id])
        ->assertRedirect(route('properties.index'));

    return inTenant($tenant->organization, fn () => Property::query()->where('demo', true)->sole());
}

test('an owner creates a demo property that arrives with sample rooms, rates and reservations', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    expect($demo->isDemo())->toBeTrue()
        ->and($demo->status)->toBe(PropertyStatus::Setup)
        ->and($demo->jurisdiction_profile)->toBe($t->a->property->jurisdiction_profile);

    inTenant($demo, function () use ($demo) {
        expect(RoomType::query()->where('property_id', $demo->id)->count())->toBeGreaterThan(0)
            ->and(Room::query()->where('property_id', $demo->id)->count())->toBeGreaterThan(0)
            ->and(RatePlan::query()->where('property_id', $demo->id)->count())->toBeGreaterThan(0)
            ->and(Reservation::query()->where('property_id', $demo->id)->count())->toBeGreaterThan(0);
    });
    expect(Activity::query()->where('event', 'created')->where('subject_id', $demo->id)->sole()->getExtraProperty('attributes')['demo'])->toBeTrue();
});

test('staff who cannot add properties cannot create a demo property', function (OrganizationRole $role) {
    $t = twoTenants();

    $this->actingAs($t->a->member($role))
        ->post(route('properties.demo.store'), ['_property' => $t->a->property->id])
        ->assertForbidden();

    expect(inTenant($t->a->organization, fn () => Property::query()->where('demo', true)->exists()))->toBeFalse();
})->with([OrganizationRole::PropertyManager, OrganizationRole::FrontDesk, OrganizationRole::Auditor]);

test('the demo flag cannot be set or cleared after creation', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    $this->actingAs($t->a->owner)
        ->put(route('properties.update', $t->a->property), PropertyForm::payload(['_property' => $t->a->property->id, 'demo' => true]))
        ->assertRedirect();
    $this->put(route('properties.update', $demo), PropertyForm::payload(['_property' => $demo->id, 'demo' => false]))
        ->assertRedirect();

    expect($t->a->property->fresh()->isDemo())->toBeFalse()
        ->and($demo->fresh()->isDemo())->toBeTrue()
        ->and(fn () => $demo->fresh()->forceFill(['demo' => false])->save())->toThrow(LogicException::class);
});

test('demo properties are marked in the switcher, the top bar and the properties list', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    $this->actingAs($t->a->owner)
        ->put(route('current-property.update'), ['property_id' => $demo->id]);

    $this->get(route('properties.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('tenant.property.demo', true)
            ->where('tenant.properties', fn ($properties) => collect($properties)->firstWhere('id', $demo->id)['demo'] === true
                && collect($properties)->firstWhere('id', $t->a->property->id)['demo'] === false)
            ->where('properties', fn ($properties) => collect($properties)->firstWhere('id', $demo->id)['demo'] === true
                && collect($properties)->firstWhere('id', $t->a->property->id)['demo'] === false));
});

test('go-live is refused for a demo property, even once its gates pass', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);
    inTenant($demo, fn () => app(MarkTaxesReviewed::class)->handle($t->a->owner, $demo));

    $this->actingAs($t->a->owner)
        ->post(route('properties.go-live', $demo), ['_property' => $demo->id])
        ->assertSessionHasErrors(['go_live' => 'A demo property can never go live.']);

    expect($demo->fresh()->status)->toBe(PropertyStatus::Setup)
        ->and(fn () => inTenant($demo, fn () => app(GoLive::class)->handle($t->a->owner, $demo)))->toThrow(ValidationException::class);
});

test('deleting a demo property removes its data and nothing else', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $demo = demoPropertyFor($t->a);
    $everythingElse = fn () => collect([RoomType::class, Room::class, RatePlan::class, Reservation::class, PropertyMembership::class, Property::class])
        ->mapWithKeys(fn (string $model) => [$model => $model::query()->withoutGlobalScopes()
            ->where($model === Property::class ? 'id' : 'property_id', '!=', $demo->id)->orderBy('id')->pluck('id')->all()]);
    $before = $everythingElse();

    $this->actingAs($t->a->owner)
        ->delete(route('properties.destroy', $demo), ['_property' => $demo->id])
        ->assertRedirect(route('properties.index'));

    expect(Property::query()->withoutGlobalScopes()->find($demo->id))->toBeNull();
    foreach ([RoomType::class, Room::class, RatePlan::class, Reservation::class, PropertyMembership::class] as $model) {
        expect($model::query()->withoutGlobalScopes()->where('property_id', $demo->id)->exists())->toBeFalse($model);
    }
    expect($everythingElse())->toEqual($before);
    expect(Activity::query()->where('event', 'deleted')->where('subject_id', $demo->id)->exists())->toBeTrue();
});

test('a real property cannot be deleted', function () {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->delete(route('properties.destroy', $t->a->otherProperty), ['_property' => $t->a->property->id])
        ->assertSessionHasErrors(['delete' => 'Only a demo property can be deleted. Archive this property instead.']);

    expect($t->a->otherProperty->fresh())->not->toBeNull();
});

test('only owners delete a demo property', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    $this->actingAs($t->a->member(OrganizationRole::GroupOperationsManager))
        ->delete(route('properties.destroy', $demo), ['_property' => $demo->id])
        ->assertForbidden();

    expect($demo->fresh())->not->toBeNull();
});

test('the real scope leaves out demo properties', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    $ids = inTenant($t->a->organization, fn () => Property::query()->real()->pluck('id')->all());

    expect($ids)->toHaveCount(2)
        ->toContain($t->a->property->id, $t->a->otherProperty->id)
        ->not->toContain($demo->id);
});
