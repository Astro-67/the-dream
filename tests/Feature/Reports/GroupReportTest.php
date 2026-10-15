<?php

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\ReservationPriceLine;
use App\Domains\Tenancy\Actions\CreateDemoProperty;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TwoTenants;

function groupReportBookings(TwoTenants $t): void
{
    $t->a->otherProperty->update(['currency' => 'TZS']);
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, fn () => app(CreateReservation::class)->handle($owner, BookingForm::details($owner)));
    }
    inTenant($t->a->otherProperty, fn () => ReservationPriceLine::query()->update(['currency' => 'TZS']));
}

function groupReportAs(TwoTenants $t, OrganizationRole $role, array $query = []): TestResponse
{
    return test()->actingAs($t->a->member($role))
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reports.group', ['from' => '2026-12-10', 'to' => '2026-12-11', ...$query]));
}

test('a group manager reads every authorized real property with totals per currency', function () {
    $t = twoTenants();
    groupReportBookings($t);
    inTenant($t->a->property, fn () => app(CreateDemoProperty::class)->handle($t->a->owner));
    $this->freezeTime();

    groupReportAs($t, OrganizationRole::GroupOperationsManager)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/group')
        ->where('filters', ['from' => '2026-12-10', 'to' => '2026-12-11', 'properties' => []])
        ->where('properties', fn ($properties) => collect($properties)->pluck('id')->sort()->values()->all()
            === collect([$t->a->property->id, $t->a->otherProperty->id])->sort()->values()->all())
        ->where('kpis.soldRoomNights', 4)
        ->where('kpis.currencies', [
            ['currency' => 'TZS', 'bookedRoomValue' => 22000, 'soldRoomNights' => 2, 'sellableRoomNights' => 2, 'bookedAdr' => 11000, 'bookedRevpar' => 11000],
            ['currency' => 'USD', 'bookedRoomValue' => 22000, 'soldRoomNights' => 2, 'sellableRoomNights' => 2, 'bookedAdr' => 11000, 'bookedRevpar' => 11000],
        ])
        ->where('refreshedAt', now()->toIso8601String()));
});

test('a single-property member sees only their property in the group view', function () {
    $t = twoTenants();
    groupReportBookings($t);

    groupReportAs($t, OrganizationRole::Auditor)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('properties.0.id', $t->a->property->id)->has('properties', 1)
        ->where('available', [['id' => $t->a->property->id, 'name' => $t->a->property->name]])
        ->where('kpis.soldRoomNights', 2)
        ->where('kpis.currencies.0.currency', 'USD')->has('kpis.currencies', 1));
});

test('batched finance excludes unassigned same-organization and other-organization postings', function () {
    $t = twoTenants();
    groupReportBookings($t);
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, fn () => app(RecordPayment::class)->handle($owner, Folio::query()->sole(), [
            'method' => 'card', 'amount' => 777, 'currency' => 'USD',
        ]));
    }

    groupReportAs($t, OrganizationRole::Auditor)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('properties', 1)->where('properties.0.unsettledFolios', 0)
        ->where('finance.openAfterDeparture', [])->where('finance.payments.rows', [])->where('finance.payments.totals', []));
});

test('a selection narrows the group to the chosen authorized properties', function () {
    $t = twoTenants();
    groupReportBookings($t);

    groupReportAs($t, OrganizationRole::Owner, ['properties' => [$t->a->otherProperty->id]])->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.properties', [$t->a->otherProperty->id])
            ->has('properties', 1)->where('properties.0.id', $t->a->otherProperty->id)
            ->where('kpis.currencies.0.currency', 'TZS')->has('kpis.currencies', 1));
});

test('supplied property IDs outside the member\'s reports access are refused', function (string $case) {
    $t = twoTenants();
    $id = match ($case) {
        'same organization, not assigned' => $t->a->otherProperty->id,
        'other organization' => $t->b->property->id,
        'demo' => inTenant($t->a->property, fn () => app(CreateDemoProperty::class)->handle($t->a->owner))->id,
        'unknown' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
    };
    $role = $case === 'same organization, not assigned' ? OrganizationRole::Auditor : OrganizationRole::Owner;

    groupReportAs($t, $role, ['properties' => [$t->a->property->id, $id]])->assertForbidden();
})->with(['same organization, not assigned', 'other organization', 'demo', 'unknown']);

test('group reports require the reports permission', function (OrganizationRole $role) {
    groupReportAs(twoTenants(), $role)->assertForbidden();
})->with([OrganizationRole::FrontDesk, OrganizationRole::Housekeeping]);

test('group reports validate dates and property IDs at the request boundary', function (array $query, array $errors) {
    $t = twoTenants();

    $this->actingAs($t->a->owner)
        ->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->getJson(route('reports.group', $query))->assertUnprocessable()->assertJsonValidationErrors($errors);
})->with([
    'reversed' => [['from' => '2026-12-11', 'to' => '2026-12-10'], ['to']],
    'malformed' => [['from' => 'tomorrow', 'to' => '2026-12-10'], ['from']],
    'properties not a list' => [['from' => '2026-12-10', 'to' => '2026-12-10', 'properties' => 'all'], ['properties']],
    'property not a string' => [['from' => '2026-12-10', 'to' => '2026-12-10', 'properties' => [['x']]], ['properties.0']],
]);
