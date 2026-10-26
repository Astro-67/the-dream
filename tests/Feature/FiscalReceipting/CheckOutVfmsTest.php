<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\FolioSummary;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Actions\GuestLeaves;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
});

/** Two guests in room 101 for 10-12 December at 100.00 USD a night, checked in. */
function inHouseForCheckOut(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast']);
        foreach ([['Anna Berg', 'P1234567'], ['Ola Berg', 'P7654321']] as [$name, $number]) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

/** Closes both nights, posting 110.00 a night, then checks out on the departure date; the owner's override covers the balance. */
function checkOutOnDeparture(TenantFixture $tenant, Reservation $reservation): Reservation
{
    foreach (['2026-12-10', '2026-12-11'] as $date) {
        inTenant($tenant->property, fn () => app(CloseBusinessDate::class)->handle($tenant->owner, $tenant->property->fresh(), $date));
    }

    return inTenant($tenant->property, fn () => app(CheckOut::class)->handle($tenant->owner, $reservation, 'Guest settles at the desk'));
}

/** @return list<OutboxMessage> */
function checkOutVfmsMessages(TenantFixture $tenant): array
{
    return inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get()->all());
}

// protects=a Sandbox check-out files guide §2.8 with every in-house guest's document, after the check-in message (#160); fails_when=the message is missing, out of order, unaccepted or lists the wrong documents; no_claim=VFMS production acceptance.
test('a Sandbox check-out files one accepted check-out message after the check-in', function () {
    $t = twoTenants();
    checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));

    $messages = checkOutVfmsMessages($t->a);
    expect(array_map(fn (OutboxMessage $message) => $message->event, $messages))->toBe(['accommodation', 'checkout']);
    $checkout = $messages[1];
    expect($checkout->status)->toBe('delivered')
        ->and($checkout->payload['request_type'])->toBe('ACCOMMODATION_CHECK_OUT')
        ->and($checkout->payload['path'])->toBe('guest/checkout/')
        ->and($checkout->payload['body'])->toEqual([
            'documentNumberList' => [['documentNumber' => 'P1234567'], ['documentNumber' => 'P7654321']],
            'currency' => 'USD',
        ]);
});

// protects=checkout requires a complete validated report before changing money (#171); fails_when=an uncertain response adjusts the folio or is replayed; no_claim=per-guest decoding (#172).
test('an incomplete or uncertain checkout never adjusts the folio or replays', function (Closure $answer) {
    $tenant = twoTenants()->a;
    $reservation = inHouseForCheckOut($tenant);
    inTenant($tenant->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-171', 'registration_id' => 'REG-171', 'token' => 'tok', 'mode' => VfmsMode::Live]));
    $calls = 0;
    Http::fake(['gateway.zanrevenue.org/vfms/api/guest/checkout/' => function () use ($answer, &$calls) {
        $calls++;

        return $answer();
    }]);

    checkOutOnDeparture($tenant, $reservation);

    $message = checkOutVfmsMessages($tenant)[1];
    expect($message->status)->toBe('unknown');
    $this->travel(2)->minutes();
    inTenant($tenant->property, function () use ($message) {
        app(DeliveryEngine::class)->deliver($message->id);
        expect(Posting::query()->where('category', 'vfms_adjustment')->exists())->toBeFalse()
            ->and($message->fresh()->attempt_count)->toBe(1);
    });
    expect($calls)->toBe(1);
    fiscalReport($tenant, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('needsAttention', true)->where('report', null)->where('guestReports', []));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/accommodationReport/'));
})->with([
    'missing totals' => [fn () => Http::response(['reportNo' => 'CO-171'])],
    'missing levy' => [fn () => Http::response(['reportNo' => 'CO-171', 'totalAmount' => 220, 'totalTax' => 0])],
    'negative total' => [fn () => Http::response(['reportNo' => 'CO-171', 'totalAmount' => -1, 'totalTax' => 0, 'infrastructureTax' => 0])],
    'guest array pending decoder' => [fn () => Http::response([['responseNumber' => 'GUEST-171', 'totalAmount' => 110]])],
    'timeout' => [fn () => throw new ConnectionException('Checkout timed out')],
]);

// protects=ADR 0015: before VFMS answers, the folio's tax is Thedream's estimate; after, it is VFMS's tax and levy and the balance matches the report (#160); fails_when=the label stays estimated, the levy is Thedream's, or the balance differs from the report total; no_claim=VFMS's real levy rates.
test('an accepted check-out replaces the estimated tax with VFMS tax and levy', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => VfmsMode::Live, 'live_since' => now()]));
    // Two nights at 110.00 with no tax rules in Thedream; VFMS assesses 1.00 levy per guest per night.
    Http::fake(['gateway.zanrevenue.org/vfms/api/guest/checkout/' => Http::response(['reportNo' => 'CO-778', 'totalAmount' => 224.0, 'totalTax' => 0.0, 'infrastructureTax' => 4.0]), 'gateway.zanrevenue.org/vfms/api/*' => Http::response(['responseNumber' => 'VFMS-171'])]);
    $reservation = inHouseForCheckOut($t->a);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'estimated'));

    checkOutOnDeparture($t->a, $reservation);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'vfms')
            ->where('totals.0.room', 22000)
            ->where('totals.0.levy', 400)
            ->where('totals.0.charges', 22400)
            ->where('totals.0.balance', 22400)
            ->where('folios.data.0.postings.data.2.category', 'vfms_adjustment')
            ->where('folios.data.0.postings.data.2.amount', 400));
});

// protects=Sandbox applies the HTTP checkout totals to the folio (#169); fails_when=the fixture totals are not retained; no_claim=real VFMS response schema (decoder changes in #172).
test('a Sandbox check-out applies the legacy HTTP fixture totals', function () {
    $t = twoTenants();
    $reservation = checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'vfms')
            ->where('totals.0.balance', 22000)
            ->where('folios.data.0.postings.data.2.amount', 0));
    expect(checkOutVfmsMessages($t->a)[1]->getAttribute('provider_references'))->toMatchArray(['totalAmount' => 220, 'infrastructureTax' => 0]);
});

/** Opens the stay's fiscal check-out report as $role (front desk by default, which works in one property only). */
function fiscalReport(TenantFixture $tenant, Reservation $reservation, OrganizationRole $role = OrganizationRole::FrontDesk)
{
    return test()->actingAs($tenant->member($role))->get(route('reservations.fiscal-report', $reservation));
}

// protects=a Sandbox report can never pass for a fiscal one (#160); fails_when=the Sandbox stamp is missing or a portal QR is shown; no_claim=print layout.
test('a Sandbox fiscal report is stamped and carries no portal QR', function () {
    $t = twoTenants();
    $reservation = checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));
    $reportNo = checkOutVfmsMessages($t->a)[1]->getAttribute('provider_references')['reportNo'];
    inTenant($t->a->property, function () use ($t) {
        $checkout = checkOutVfmsMessages($t->a)[1];
        $checkout->attempts()->create(['attempt_number' => 2, 'outcome' => 'accepted', 'request_body' => json_encode($checkout->payload, JSON_THROW_ON_ERROR)]);
    });

    fiscalReport($t->a, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page->component('fiscal-receipting/checkout-report')
        ->where('status', 'sandbox')
        ->where('qrSvg', null)->where('portalUrl', null)
        ->where('report.number', $reportNo)
        ->where('report.currency', 'USD')
        ->where('report.room', 22000)->where('report.extras', 0)->where('report.levy', 0)->where('report.total', 22000)
        ->where('stay.nights', 2)
        ->where('guests', ['Anna Berg', 'Ola Berg'])
        ->where('property.name', $t->a->property->name));
});

// protects=a Live report carries the VFMS portal QR for its report number (guide note, #160); fails_when=the URL is wrong, uses another number or no QR is drawn; no_claim=that the portal resolves the number.
test('a Live fiscal report shows the portal QR for its report number', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => VfmsMode::Live, 'live_since' => now()]));
    Http::fake(['gateway.zanrevenue.org/vfms/api/guest/checkout/' => Http::response(['reportNo' => 'CO-778', 'totalAmount' => 224.0, 'totalTax' => 0.0, 'infrastructureTax' => 4.0]), 'gateway.zanrevenue.org/vfms/api/*' => Http::response(['responseNumber' => 'VFMS-171'])]);
    $reservation = checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));

    fiscalReport($t->a, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status', 'live')
        ->where('portalUrl', 'https://portalvfms.zanrevenue.org/checkout-report/guest/CO-778')
        ->where('qrSvg', fn (string $svg) => str_starts_with($svg, '<svg'))
        ->where('report.number', 'CO-778'));
});

// protects=FSC-07: VFMS being down never blocks check-out, and the report waits for every message (#160); fails_when=check-out fails or the report shows figures before VFMS accepts; no_claim=when the retry succeeds.
test('with VFMS failing check-out completes and the report waits for VFMS', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => VfmsMode::Live, 'live_since' => now()]));
    Http::fake(['gateway.zanrevenue.org/vfms/api/*' => Http::response([], 503)]);

    $reservation = checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));

    expect($reservation->status->value)->toBe('checked_out');
    fiscalReport($t->a, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status', 'waiting')->where('report', null)->where('qrSvg', null));
});

// protects=only users who may view the stay's folio open its fiscal report (#160); fails_when=a role without reservation access opens it, or one with access is refused; no_claim=cross-organization access (isolation coverage).
test('only users who can view the stay open its fiscal report', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = checkOutOnDeparture($t->a, inHouseForCheckOut($t->a));
    $actor = $role === OrganizationRole::Owner ? $t->a->owner : $t->a->member($role);

    $response = test()->actingAs($actor)->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->get(route('reservations.fiscal-report', $reservation));

    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with(array_map(fn (OrganizationRole $role) => [$role, $role !== OrganizationRole::Housekeeping], OrganizationRole::cases()));

// protects=recorded guest reports settle shared room VAT once and keep identity encrypted (#172); fails_when=a report is lost, VAT doubles, or Finance sees documents; no_claim=live acceptance or print pagination.
test('recorded checkout stores every report and projects authorized identity only', function (VfmsMode $mode, bool $earlyDeparture) {
    $tenant = twoTenants()->a;
    $reports = json_decode(file_get_contents(__DIR__.'/../../Fixtures/vfms/checkout-two-guests.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['P1234567', 'P7654321'] as $index => $document) {
        $reports[$index]['guest']['documentNo'] = $document;
    }
    config(['services.vfms.test_viewer_url' => 'https://vfms-viewer.example']);
    if ($mode === VfmsMode::Live) {
        inTenant($tenant->property, fn () => VfmsCredential::query()->create([
            'mode' => $mode, 'integration_id' => 'INT-172', 'registration_id' => 'REG-172', 'token' => 'TEST-TOKEN',
        ]));
    }
    $base = $mode === VfmsMode::Live ? 'https://gateway.zanrevenue.org/vfms/api/' : VfmsHttp::TEST_URL;
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        $base.'guest/checkout/' => fn (Request $request) => Http::response(array_values(array_filter(
            $reports, fn (array $report) => in_array($report['guest']['documentNo'], array_column($request['documentNumberList'], 'documentNumber'), true),
        ))),
        $base.'service/' => Http::response(['receiptNumber' => 'RECEIPT-172']),
        $base.'*' => Http::response(['responseNumber' => 'AC-172']),
    ]);
    $reservation = inHouseForCheckOut($tenant);
    inTenant($tenant->property, fn () => app(PostExtraCharge::class)->handle(
        $tenant->owner, Folio::query()->where('reservation_id', $reservation->id)->sole(),
        ['category' => 'bar', 'description' => 'Water', 'quantity' => 2, 'unit_price' => 1000],
    ));
    if ($earlyDeparture) {
        inTenant($tenant->property, fn () => app(GuestLeaves::class)->handle($tenant->owner, $reservation, Guest::query()->where('name', 'Ola Berg')->sole()->id));
    }
    $reservation = checkOutOnDeparture($tenant, $reservation);
    $checkout = collect(checkOutVfmsMessages($tenant))->last();
    $numbers = $earlyDeparture ? ['TEST-REPORT-2', 'TEST-REPORT-1'] : ['TEST-REPORT-1', 'TEST-REPORT-2'];

    expect($checkout->status)->toBe('delivered')
        ->and(json_decode($checkout->getAttribute('provider_references')['reportNumbers'], true))->toBe($numbers)
        ->and(json_encode($checkout->getAttribute('provider_references')))->not->toContain('P1234567', 'Test Guest');
    inTenant($tenant->property, function () use ($checkout, $reservation) {
        $attempt = $checkout->attempts()->sole();
        expect($attempt->getRawOriginal('response_body'))->not->toContain('P1234567')
            ->and($attempt->response_body)->toContain('P1234567');
        $totals = collect(FolioSummary::totals($reservation))->firstWhere('currency', 'USD');
        expect($totals)->toMatchArray(['charges' => 13000, 'vat' => 1565, 'levy' => 1000])
            ->and(Posting::query()->where('category', 'vfms_adjustment')->sole()->reference)->toBe('TEST-REPORT-1');
    });
    $viewer = $mode === VfmsMode::Live ? 'https://portalvfms.zanrevenue.org' : 'https://vfms-viewer.example';
    fiscalReport($tenant, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('status', $mode->value)
        ->where('business.tinNumber', 'TEST-TIN')
        ->where('report.room', 10000)->where('report.extras', 2000)
        ->where('report.vat', 1565)->where('report.levy', 1000)->where('report.total', 13000)
        ->where('serviceReceipts.0.number', 'RECEIPT-172')->where('serviceReceipts.0.amount', 2000)
        ->has('guestReports', 2)
        ->where('guestReports.0.number', $numbers[0])
        ->where('guestReports.1.number', $numbers[1])
        ->where('guestReports.0.departedEarly', $earlyDeparture)
        ->where('guestReports.0.identity.documentNo', $earlyDeparture ? 'P7654321' : 'P1234567')
        ->where('guestReports.0.portalUrl', $viewer.'/checkout-report/guest/'.$numbers[0])
        ->where('guestReports.1.portalUrl', $viewer.'/checkout-report/guest/'.$numbers[1])
        ->where('guestReports.1.qrSvg', fn (string $svg) => str_starts_with($svg, '<svg')));
    fiscalReport($tenant, $reservation, OrganizationRole::Finance)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('guestReports.0.identity', null)->where('guestReports.1.identity', null));
})->with([
    [VfmsMode::Sandbox, false], [VfmsMode::Live, false],
    [VfmsMode::Sandbox, true], [VfmsMode::Live, true],
]);
