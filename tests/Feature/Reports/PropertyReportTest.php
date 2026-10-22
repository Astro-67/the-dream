<?php

use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Property;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('an auditor reads property KPIs with business dates currency and computation time', function () {
    $t = twoTenants();
    $this->freezeTime();
    $t->a->property->update(['business_date' => '2026-12-10']);
    inTenant($t->a->property, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
    inTenant($t->a->otherProperty, function () use ($t) {
        app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        InventoryRecord::query()->update(['sellable_count' => 50]);
    });

    $this->actingAs($t->a->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index'))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('filters', ['from' => '2026-12-10', 'to' => '2026-12-10', 'settled' => false])
        ->where('kpis.soldRoomNights', 1)->where('kpis.sellableRoomNights', 1)
        ->where('kpis.occupancy', 1)
        ->where('kpis.currencies.0.currency', 'USD')
        ->where('kpis.currencies.0.bookedRoomValue', 11000)
        ->where('refreshedAt', now()->toIso8601String()));
});

test('demo properties cannot contribute to property reports', function () {
    $t = twoTenants();
    $this->actingAs($t->a->owner)->post(route('properties.demo.store'), ['_property' => $t->a->property->id])->assertRedirect();
    $demo = inTenant($t->a->organization, fn () => Property::query()->where('demo', true)->sole());

    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $demo->id])
        ->get(route('reports.index'))->assertNotFound();
});

test('property reports require a current property rather than an organization-only context', function () {
    [$owner, $organization] = organizationWithOwner();

    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => null])
        ->getJson(route('reports.index'))->assertForbidden();
});

test('reports validate dates at the request boundary', function (array $range, array $errors) {
    [$owner, $organization, $property] = organizationWithOwner();

    $this->actingAs($owner)->withSession(['tenancy.organization_id' => $organization->id, 'tenancy.property_id' => $property->id])
        ->getJson(route('reports.index', $range))->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    'reversed' => [['from' => '2026-12-11', 'to' => '2026-12-10'], ['to']],
    'impossible date' => [['from' => '2026-02-30', 'to' => '2026-03-01'], ['from']],
    'malformed' => [['from' => 'tomorrow', 'to' => '2026-12-10'], ['from']],
    'array' => [['from' => ['2026-12-10'], 'to' => '2026-12-11'], ['from']],
    'empty' => [['from' => '', 'to' => ''], ['from', 'to']],
]);

test('report permission is enforced for property staff', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $response = $this->actingAs($t->a->member($role))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.index', ['from' => '2026-12-10', 'to' => '2026-12-11']));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([
    [OrganizationRole::Owner, true],
    [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::PropertyManager, true],
    [OrganizationRole::Finance, true],
    [OrganizationRole::Auditor, true],
    [OrganizationRole::FrontDesk, false],
    [OrganizationRole::Housekeeping, false],
]);
