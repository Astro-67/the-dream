<?php

use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
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

// protects=ADR 0015: before VFMS answers, the folio's tax is Thedream's estimate; after, it is VFMS's tax and levy and the balance matches the report (#160); fails_when=the label stays estimated, the levy is Thedream's, or the balance differs from the report total; no_claim=VFMS's real levy rates.
test('an accepted check-out replaces the estimated tax with VFMS tax and levy', function () {
    $t = twoTenants();
    $reservation = inHouseForCheckOut($t->a);

    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'estimated'));

    checkOutOnDeparture($t->a, $reservation);

    // Two nights at 110.00, no tax rules; the Sandbox levy is 1.00 per guest per night.
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk))->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'vfms')
            ->where('totals.0.room', 22000)
            ->where('totals.0.levy', 400)
            ->where('totals.0.charges', 22400)
            ->where('totals.0.balance', 22400)
            ->where('folios.data.0.postings.data.2.category', 'vfms_adjustment'));
    $report = checkOutVfmsMessages($t->a)[1]->getAttribute('provider_references');
    expect($report)->toMatchArray(['totalAmount' => 224, 'infrastructureTax' => 4])
        ->and($report['reportNo'])->toStartWith('SBX-');
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

    fiscalReport($t->a, $reservation)->assertOk()->assertInertia(fn (Assert $page) => $page->component('fiscal-receipting/checkout-report')
        ->where('status', 'sandbox')
        ->where('qrSvg', null)->where('portalUrl', null)
        ->where('report.number', $reportNo)
        ->where('report.currency', 'USD')
        ->where('report.room', 22000)->where('report.extras', 0)->where('report.levy', 400)->where('report.total', 22400)
        ->where('stay.nights', 2)
        ->where('guests', ['Anna Berg', 'Ola Berg'])
        ->where('property.name', $t->a->property->name));
});

// protects=a Live report carries the VFMS portal QR for its report number (guide note, #160); fails_when=the URL is wrong, uses another number or no QR is drawn; no_claim=that the portal resolves the number.
test('a Live fiscal report shows the portal QR for its report number', function () {
    $t = twoTenants();
    inTenant($t->a->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1', 'registration_id' => 'REG-1', 'token' => 'tok', 'mode' => VfmsMode::Live, 'live_since' => now()]));
    Http::fake(['gateway.zanrevenue.org/api/guest/checkout/' => Http::response(['reportNo' => 'CO-778', 'totalAmount' => 224.0, 'totalTax' => 0.0, 'infrastructureTax' => 4.0]), 'gateway.zanrevenue.org/*' => Http::response([])]);
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
    Http::fake(['gateway.zanrevenue.org/*' => Http::response([], 503)]);

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
