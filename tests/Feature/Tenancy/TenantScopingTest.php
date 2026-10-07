<?php

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use Illuminate\Support\Facades\Route;

/*
 * Probe routes stand in for the operational pages later tickets add: one inside
 * the `tenant` middleware, one (wrongly) outside it.
 */
beforeEach(function () {
    Route::middleware(['web', 'auth'])->group(function () {
        Route::get('/_probe/unscoped/properties', fn () => Property::query()->pluck('name'));
    });

    Route::middleware(['web', 'auth', 'tenant'])->group(function () {
        Route::get('/_probe/properties', fn () => Property::query()->orderBy('name')->pluck('name'));
        Route::get('/_probe/properties/{property}', fn (Property $property) => $property->name);
        Route::post('/_probe/property-memberships', function () {
            /** @var User $user */
            $user = auth()->user();

            return PropertyMembership::query()->create(['user_id' => $user->id])
                ->only(['organization_id', 'property_id']);
        });
    });
});

test('a request that touches tenant data outside any tenant context fails instead of running unscoped', function () {
    [$owner] = organizationWithOwner();

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->get('/_probe/unscoped/properties'))
        ->toThrow(MissingTenantContext::class);
});

test('a member only ever sees their own organization\'s records', function () {
    [$ownerA] = organizationWithOwner(['organization_name' => 'Org A', 'property_name' => 'Alpha Lodge']);
    organizationWithOwner(['organization_name' => 'Org B', 'property_name' => 'Bravo Resort']);

    $this->actingAs($ownerA)
        ->get('/_probe/properties')
        ->assertOk()
        ->assertExactJson(['Alpha Lodge']);
});

test('a record of another organization cannot be reached by its ID', function () {
    [$ownerA, , $propertyA] = organizationWithOwner(['property_name' => 'Alpha Lodge']);
    [, , $propertyB] = organizationWithOwner(['property_name' => 'Bravo Resort']);

    $this->actingAs($ownerA)->get("/_probe/properties/{$propertyA->id}")->assertOk()->assertSee('Alpha Lodge');
    $this->actingAs($ownerA)->get("/_probe/properties/{$propertyB->id}")->assertNotFound();
});

test('records created in a request get the current organization and property filled in', function () {
    [$owner, $organization, $property] = organizationWithOwner();

    $this->actingAs($owner)
        ->post('/_probe/property-memberships')
        ->assertOk()
        ->assertExactJson(['organization_id' => $organization->id, 'property_id' => $property->id]);
});
