<?php

use App\Domains\Inventory\Models\Room;
use App\Domains\Inventory\Models\RoomType;
use App\Domains\Rates\Actions\CreateRatePlan;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Subscriptions\PlanUsage;
use App\Domains\Tenancy\Actions\ChangeMembership;
use App\Domains\Tenancy\Actions\GoLive;
use App\Domains\Tenancy\Actions\MarkTaxesReviewed;
use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
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
    expect(Activity::query()->where('event', 'refused')->where('properties->action', 'properties.demo.store')->exists())->toBeTrue();
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

test('renamed demo properties stay marked in membership and invitation lists and selectors', function () {
    Notification::fake();
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);
    $membership = inTenant($t->a->organization, function () use ($t, $demo) {
        $demo->update(['name' => 'Practice property']);
        $membership = OrganizationMembership::query()->where('user_id', $t->a->member(OrganizationRole::FrontDesk)->id)->sole();
        app(ChangeMembership::class)->handle($t->a->owner, $membership, MembershipGrant::properties(OrganizationRole::FrontDesk, [$demo->id]));

        return $membership;
    });
    $label = 'Practice property (Demo)';

    $this->actingAs($t->a->owner)->get(route('members.index'))
        ->assertInertia(fn (Assert $page) => $page->where('members', fn ($members) => collect($members)->firstWhere('id', $membership->id)['properties'] === [$label]));
    foreach (['members.edit', 'invitations.create'] as $route) {
        $this->get(route($route, $route === 'members.edit' ? $membership : []))
            ->assertInertia(fn (Assert $page) => $page->where('properties', fn ($properties) => collect($properties)->firstWhere('value', $demo->id)['label'] === $label));
    }
    $this->post(route('invitations.store'), [
        '_property' => $t->a->property->id,
        'email' => 'demo-staff@example.test',
        'role' => 'front_desk',
        'all_properties' => false,
        'property_ids' => [$demo->id],
        'expires_in_days' => 7,
    ])->assertSessionHasNoErrors();
    $this->get(route('members.index'))
        ->assertInertia(fn (Assert $page) => $page->where('invitations.0.properties', [$label]));
});

test('deleting a demo property removes its data and nothing else', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => BookingForm::details($t->a->owner));
    $demo = demoPropertyFor($t->a);
    foreach ([$t->a->property, $demo] as $property) {
        inTenant($property, fn () => app(CreateRatePlan::class)->handle($t->a->owner, [
            'name' => 'Derived sample rate',
            'code' => 'DER',
            'base_rate_plan_id' => RatePlan::query()->sole()->id,
            'adjustment_kind' => 'percent',
            'adjustment' => -10,
        ]));
    }
    $tables = collect(Schema::getTableListing(Schema::getCurrentSchemaName(), false))
        ->filter(fn (string $table) => $table === 'properties' || Schema::hasColumn($table, 'property_id'));
    $everythingElse = fn () => $tables->mapWithKeys(fn (string $table) => [$table => DB::table($table)
        ->where($table === 'properties' ? 'id' : 'property_id', '!=', $demo->id)->get()->all()]);
    $before = $everythingElse();

    $this->actingAs($t->a->owner)
        ->delete(route('properties.destroy', $demo), ['_property' => $demo->id])
        ->assertRedirect(route('properties.index'));

    expect(Property::query()->withoutGlobalScopes()->find($demo->id))->toBeNull();
    foreach ($tables as $table) {
        expect(DB::table($table)->where($table === 'properties' ? 'id' : 'property_id', $demo->id)->exists())->toBeFalse($table);
    }
    $after = $everythingElse();
    foreach ($before as $table => $rows) {
        expect($after[$table])->toEqualCanonicalizing($rows);
    }
    expect(Activity::query()->where('event', 'deleted')->where('subject_id', $demo->id)->exists())->toBeTrue();
});

test('deleting the last active demo property is refused without removing its sample data', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);
    inTenant($t->a->organization, fn () => Property::query()->real()->update(['archived_at' => now()]));
    $rooms = inTenant($demo, fn () => Room::query()->count());

    $this->actingAs($t->a->owner)->delete(route('properties.destroy', $demo), ['_property' => $demo->id])
        ->assertSessionHasErrors(['delete' => 'The organization needs another active property before the demo property can be deleted.']);

    expect($demo->fresh())->not->toBeNull()
        ->and(inTenant($demo, fn () => Room::query()->count()))->toBe($rooms);
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
    expect(Activity::query()->where('event', 'refused')->where('subject_id', $demo->id)
        ->where('properties->action', 'properties.destroy')->exists())->toBeTrue();
});

test('the real scope leaves out demo properties', function () {
    $t = twoTenants();
    $demo = demoPropertyFor($t->a);

    $ids = inTenant($t->a->organization, fn () => Property::query()->real()->pluck('id')->all());

    expect($ids)->toHaveCount(2)
        ->toContain($t->a->property->id, $t->a->otherProperty->id)
        ->not->toContain($demo->id);
});

test('plan usage leaves out demo properties and their rooms', function () {
    $t = twoTenants();
    $before = inTenant($t->a->organization, fn () => PlanUsage::current());

    demoPropertyFor($t->a);

    expect(inTenant($t->a->organization, fn () => PlanUsage::current()))->toBe($before);
});
