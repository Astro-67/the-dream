<?php

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Support\Tenancy\Exceptions\MissingTenantContext;
use App\Support\Tenancy\Exceptions\TenantMismatch;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Spatie\Activitylog\Models\Activity;

test('within sets the organization, property and spatie team, and restores them afterwards', function () {
    [$owner, $organizationA, $propertyA] = organizationWithOwner();
    [, $organizationB] = organizationWithOwner();

    Tenancy::within(TenantContext::forProperty($propertyA), function () use ($organizationA, $organizationB, $propertyA, $owner) {
        expect(Tenancy::organization()?->is($organizationA))->toBeTrue()
            ->and(Tenancy::property()?->is($propertyA))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($organizationA->id)
            ->and($owner->fresh()->hasRole('owner'))->toBeTrue();

        Tenancy::within(TenantContext::forOrganization($organizationB), function () use ($organizationB, $owner) {
            expect(getPermissionsTeamId())->toBe($organizationB->id)
                ->and(Tenancy::property())->toBeNull()
                ->and($owner->fresh()->hasRole('owner'))->toBeFalse();
        });

        expect(Tenancy::property()?->is($propertyA))->toBeTrue()
            ->and(getPermissionsTeamId())->toBe($organizationA->id);
    });

    expect(Tenancy::current())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

test('within restores the previous context when the callback throws', function () {
    [, $organization] = organizationWithOwner();

    expect(fn () => Tenancy::within(TenantContext::forOrganization($organization), fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class, 'boom');

    expect(Tenancy::current())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

test('a context naming a property of another organization is refused', function () {
    [, $organizationA] = organizationWithOwner();
    [, , $propertyB] = organizationWithOwner();

    expect(fn () => Tenancy::within(TenantContext::fromIds($organizationA->id, $propertyB->id), fn () => null))
        ->toThrow(TenantMismatch::class);
});

test('a record cannot be written for another organization than the one in context', function () {
    [$owner, $organizationA] = organizationWithOwner();
    [, , $propertyB] = organizationWithOwner();

    expect(fn () => inTenant($organizationA, fn () => PropertyMembership::query()->create([
        'user_id' => $owner->id,
        'organization_id' => $propertyB->organization_id,
        'property_id' => $propertyB->id,
    ])))->toThrow(TenantMismatch::class);
});

test('tenant-owned records cannot be queried or created without a context', function () {
    [$owner] = organizationWithOwner();

    expect(fn () => Property::query()->count())->toThrow(MissingTenantContext::class)
        ->and(fn () => PropertyMembership::query()->create(['user_id' => $owner->id]))->toThrow(MissingTenantContext::class);
});

test('the platform context sees every organization and logs who entered it and why', function () {
    [$owner] = organizationWithOwner(['property_name' => 'Alpha Lodge']);
    organizationWithOwner(['property_name' => 'Bravo Resort']);
    $this->actingAs($owner);

    $names = Tenancy::within(TenantContext::platform('Isolation test'), fn () => Property::query()->orderBy('name')->pluck('name')->all());

    expect($names)->toBe(['Alpha Lodge', 'Bravo Resort']);

    $entry = Activity::query()->where('log_name', 'platform')->sole();
    expect($entry->getExtraProperty('reason'))->toBe('Isolation test')
        ->and($entry->causer?->is($owner))->toBeTrue();
});

test('the signed-in user\'s permission checks follow the context they are in', function () {
    [$owner, $organizationA] = organizationWithOwner();
    [, $organizationB] = organizationWithOwner();
    $this->actingAs($owner);

    $hasOwnerRole = fn () => auth()->user()?->hasRole('owner');

    expect(inTenant($organizationA, $hasOwnerRole))->toBeTrue()
        ->and(inTenant($organizationB, $hasOwnerRole))->toBeFalse()
        ->and(inTenant($organizationA, $hasOwnerRole))->toBeTrue();
});
