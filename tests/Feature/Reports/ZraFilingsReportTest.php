<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        VfmsHttp::TEST_URL.'accommodation/' => Http::sequence()
            ->push(['responseNumber' => 'AC-OTHER'])
            ->push(['responseNumber' => 'AC-174'])
            ->push([], 500),
        VfmsHttp::TEST_URL.'service/' => Http::response(['statusCode' => 5000, 'statusMessage' => 'Service refused']),
    ]);
});

/** Checks in one stay per room number, each with a documented guest; rooms after the first are added. */
function zraFilingsStays(TenantFixture $tenant, array $rooms): array
{
    $details = BookingForm::details($tenant->owner);
    foreach (array_slice($rooms, 1) as $number) {
        app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($details['room_type_id'], ['number' => $number]));
    }
    RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);

    return array_map(function (string $number) use ($tenant, $details) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->where('number', $number)->sole()->id]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => "Guest {$number}", 'documents' => [['type' => 'passport', 'number' => "DOC-{$number}", 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    }, $rooms);
}

function zraFilingsExtra(TenantFixture $tenant, Reservation $reservation, string $key): void
{
    app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->where('reservation_id', $reservation->id)->sole(), [
        'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => $key,
    ]);
}

// protects=the ZRA filings report counts and lists only this property's VFMS calls in the range (#174 AC2); fails_when=another property's calls leak in or a status lands in the wrong count; no_claim=MySQL ordering.
test('the ZRA filings report counts and lists the property calls in range only', function () {
    $tenant = twoTenants()->a;
    $tenant->otherProperty->update(['jurisdiction_profile' => 'zanzibar', 'zra_hotel_code' => 'ZH-OTHER']);
    inTenant($tenant->otherProperty, fn () => zraFilingsStays($tenant, ['101']));
    [$filed, $unknown] = inTenant($tenant->property, function () use ($tenant) {
        [$filed, $unknown] = zraFilingsStays($tenant, ['101', '102']);
        zraFilingsExtra($tenant, $filed, 'water-refused');
        zraFilingsExtra($tenant, $filed, 'water-waiting');

        return [$filed, $unknown];
    });

    $session = ['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id];
    $this->actingAs($tenant->member(OrganizationRole::Auditor))->withSession($session)
        ->get(route('reports.index', ['view' => 'filings', 'from' => '2026-12-10', 'to' => '2026-12-10']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/index')
        ->where('filters.view', 'filings')
        ->where('filings.counts', [
            ['status' => 'filed', 'label' => 'Filed', 'count' => 1],
            ['status' => 'refused', 'label' => 'Refused', 'count' => 1],
            ['status' => 'unknown', 'label' => 'Unknown', 'count' => 1],
            ['status' => 'waiting', 'label' => 'Waiting', 'count' => 1],
        ])
        ->has('filings.calls.data', 4)
        ->where('filings.calls.data.0', fn ($row) => $row->only(['date', 'reservationNumber', 'call', 'amount', 'currency', 'status', 'statusLabel', 'zraNumber'])->all() === [
            'date' => '2026-12-10', 'reservationNumber' => $filed->reservation_number, 'call' => 'Check-in',
            'amount' => 11000, 'currency' => 'USD', 'status' => 'filed', 'statusLabel' => 'Filed', 'zraNumber' => 'AC-174',
        ])
        ->where('filings.calls.data.1.call', 'Extras')->where('filings.calls.data.1.status', 'refused')->where('filings.calls.data.1.amount', 2000)
        ->where('filings.calls.data.2.call', 'Extras')->where('filings.calls.data.2.status', 'waiting')
        ->where('filings.calls.data.3.reservationNumber', $unknown->reservation_number)->where('filings.calls.data.3.status', 'unknown')
        ->where('filings.calls.data.3.zraNumber', null));

    $this->get(route('reports.index', ['view' => 'filings', 'from' => '2026-12-11', 'to' => '2026-12-12']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filings.counts.0.count', 0)
        ->has('filings.calls.data', 0));
});

// protects=the ZRA filings CSV carries the same calls as the report, for the current property only (#174); fails_when=columns drift from the screen or another property's call appears; no_claim=spreadsheet locale handling.
test('the ZRA filings CSV lists the property calls in range', function () {
    $tenant = twoTenants()->a;
    $tenant->otherProperty->update(['jurisdiction_profile' => 'zanzibar', 'zra_hotel_code' => 'ZH-OTHER']);
    inTenant($tenant->otherProperty, fn () => zraFilingsStays($tenant, ['101']));
    $filed = inTenant($tenant->property, fn () => zraFilingsStays($tenant, ['101'])[0]);

    $response = $this->actingAs($tenant->member(OrganizationRole::Auditor))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reports.filings.csv', ['from' => '2026-12-10', 'to' => '2026-12-10']))
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('zra-filings-2026-12-10-2026-12-10.csv');

    expect(array_map('str_getcsv', explode("\n", trim($response->getContent()))))->toBe([
        ['Business date', 'Reservation', 'Call', 'Currency', 'Amount', 'Status', 'ZRA number'],
        ['2026-12-10', $filed->reservation_number, 'Check-in', 'USD', '110.00', 'Filed', 'AC-174'],
    ])->and($response->getContent())->not->toContain('AC-OTHER');
});

test('the ZRA filings CSV follows report permissions', function (OrganizationRole $role, int $status) {
    $tenant = twoTenants()->a;

    $this->actingAs($tenant->member($role))
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reports.filings.csv', ['from' => '2026-12-10', 'to' => '2026-12-10']))
        ->assertStatus($status);
})->with([
    'front desk' => [OrganizationRole::FrontDesk, 403],
    'auditor' => [OrganizationRole::Auditor, 200],
]);
