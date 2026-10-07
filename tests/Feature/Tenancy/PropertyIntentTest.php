<?php

use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Every form that changes data carries the property it was opened for (`_property`).
 * The `property.intent` middleware saves it to that property if the member may still
 * act there, or rejects it. It is never saved to the property selected since.
 *
 * The probe route stands in for any property-level form: it creates a
 * property-owned record, which lands in whichever property the request runs in.
 */
beforeEach(function () {
    Route::middleware(['web', 'auth', 'tenant', 'property.intent'])
        ->post('_probe/property-records', function () {
            $record = PropertyMembership::query()->create(['user_id' => User::factory()->create()->id]);

            return response()->json(['property_id' => $record->property_id]);
        });

    Route::middleware(['web', 'auth', 'tenant', 'property.intent'])
        ->put('_probe/property-records/{record}', fn (PropertyMembership $record) => response()->json(['id' => $record->id]));
});

/**
 * Property-owned records of a property, counted inside its own context.
 */
function recordsIn(Property $property): int
{
    return inTenant($property, fn () => PropertyMembership::query()->count());
}

test('a form opened for property A and submitted after switching to B is saved to A', function () {
    $t = twoTenants();
    $before = [recordsIn($t->a->property), recordsIn($t->a->otherProperty)];

    $this->actingAs($t->a->owner)
        ->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id]);

    $this->postJson('/_probe/property-records', ['_property' => $t->a->property->id])
        ->assertOk()
        ->assertJson(['property_id' => $t->a->property->id]);

    expect([recordsIn($t->a->property), recordsIn($t->a->otherProperty)])->toBe([$before[0] + 1, $before[1]]);

    // Saving to A does not switch the member back: B stays selected.
    $this->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('tenant.property.id', $t->a->otherProperty->id));
});

test('a form opened for a property the member may no longer act in is rejected, not saved to the current property', function () {
    $t = twoTenants();
    $frontDesk = $t->a->member(OrganizationRole::FrontDesk);
    inTenant($t->a->organization, fn () => PropertyMembership::query()->create([
        'user_id' => $frontDesk->id,
        'property_id' => $t->a->otherProperty->id,
    ]));

    // Opened the form in A, then lost access to A while working in B.
    $this->actingAs($frontDesk)->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id]);
    inTenant($t->a->property, fn () => PropertyMembership::query()->where('user_id', $frontDesk->id)->delete());
    $before = [recordsIn($t->a->property), recordsIn($t->a->otherProperty)];

    $this->postJson('/_probe/property-records', ['_property' => $t->a->property->id])->assertForbidden();

    expect([recordsIn($t->a->property), recordsIn($t->a->otherProperty)])->toBe($before);
});

test('a form carrying another organization\'s property is rejected', function () {
    $t = twoTenants();
    $before = [recordsIn($t->a->property), recordsIn($t->a->otherProperty), recordsIn($t->b->property)];

    $this->actingAs($t->a->owner)
        ->postJson('/_probe/property-records', ['_property' => $t->b->property->id])
        ->assertForbidden();

    expect([recordsIn($t->a->property), recordsIn($t->a->otherProperty), recordsIn($t->b->property)])->toBe($before);
});

test('a form that does not say which property it was opened for is rejected', function () {
    $t = twoTenants();
    $before = recordsIn($t->a->property) + recordsIn($t->a->otherProperty);

    $this->actingAs($t->a->owner)
        ->post('/_probe/property-records')
        ->assertSessionHasErrors('_property');

    expect(recordsIn($t->a->property) + recordsIn($t->a->otherProperty))->toBe($before);
});

test('records in the URL are looked up in the property the form was opened for', function () {
    $t = twoTenants();
    $record = inTenant($t->a->property, fn () => PropertyMembership::query()->firstOrFail());

    $this->actingAs($t->a->owner)
        ->put(route('current-property.update'), ['property_id' => $t->a->otherProperty->id]);

    $this->putJson("/_probe/property-records/{$record->id}", ['_property' => $t->a->property->id])
        ->assertOk()
        ->assertJson(['id' => $record->id]);
    $this->putJson("/_probe/property-records/{$record->id}", ['_property' => $t->a->otherProperty->id])
        ->assertNotFound();
});

test('the property switch itself and page visits need no property intent', function () {
    $t = twoTenants();
    Route::middleware(['web', 'auth', 'tenant', 'property.intent'])->get('_probe/page', fn () => 'ok');

    $this->actingAs($t->a->owner)->get('/_probe/page')->assertOk();
});
