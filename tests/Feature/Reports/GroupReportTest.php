<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
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
        ->where('filters', ['from' => '2026-12-10', 'to' => '2026-12-11', 'properties' => [], 'settled' => false])
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

test('group folio balances list each authorized property\'s unsettled folios with its identity and currency', function () {
    $t = twoTenants();
    groupReportBookings($t);
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, fn () => app(PostExtraCharge::class)->handle($owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]));
    }

    groupReportAs($t, OrganizationRole::Owner)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('finance.folios.total', 2)
        ->where('finance.folios.data', fn ($rows) => collect($rows)->map(fn ($row) => [$row['propertyName'], $row['currency'], $row['balance']])->sort()->values()->all()
            === collect([[$t->a->property->name, 'USD', 1500], [$t->a->otherProperty->name, 'USD', 1500]])->sort()->values()->all()));
    groupReportAs($t, OrganizationRole::Auditor)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('finance.folios.total', 1)->where('finance.folios.data.0.propertyId', $t->a->property->id));
});

test('group folio balances paginate while keeping the selected properties and dates', function () {
    $t = twoTenants();
    groupReportBookings($t);
    inTenant($t->a->otherProperty, function () use ($t) {
        $reservation = Reservation::query()->sole();
        for ($index = 0; $index < 51; $index++) {
            app(PostExtraCharge::class)->handle($t->a->owner, app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD'), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 100]);
        }
    });

    groupReportAs($t, OrganizationRole::Owner, ['properties' => [$t->a->otherProperty->id], 'folios' => 2])->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('finance.folios.total', 51)->where('finance.folios.current_page', 2)->has('finance.folios.data', 1)
            ->where('finance.folios.prev_page_url', fn ($url) => str_contains(urldecode($url), 'properties[0]='.$t->a->otherProperty->id)
                && str_contains($url, 'from=2026-12-10') && str_contains($url, 'to=2026-12-11')));
});

// protects=group reconciliation lists only permitted properties, settled only on request; fails_when=a settled folio is omitted or another organization appears.
test('group folio balances add guest and charges per row and include settled folios departing in range when asked', function () {
    $t = twoTenants();
    groupReportBookings($t);
    foreach ([$t->a->property, $t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, function () use ($owner) {
            app(PostExtraCharge::class)->handle($owner, Folio::query()->sole(), ['category' => 'minibar', 'quantity' => 1, 'unit_price' => 1500]);
            app(RecordPayment::class)->handle($owner, Folio::query()->sole(), ['method' => 'card', 'amount' => 1500, 'currency' => 'USD']);
        });
    }

    groupReportAs($t, OrganizationRole::Owner)->assertOk()->assertInertia(fn (Assert $page) => $page->where('finance.folios.total', 0));
    groupReportAs($t, OrganizationRole::Owner, ['from' => '2026-12-12', 'to' => '2026-12-12', 'settled' => 1])->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.settled', true)->where('finance.folios.total', 2)
        ->where('finance.folios.data', fn ($rows) => collect($rows)->every(fn ($row) => $row['balance'] === 0 && $row['charges'] === 1500 && $row['netPayments'] === 1500 && $row['guest'] === 'Travel Booker')
            && collect($rows)->pluck('propertyName')->sort()->values()->all() === collect([$t->a->property->name, $t->a->otherProperty->name])->sort()->values()->all()));
});
