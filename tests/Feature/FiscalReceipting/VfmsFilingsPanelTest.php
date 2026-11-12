<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
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
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        VfmsHttp::TEST_URL.'accommodation/' => Http::response(['responseNumber' => 'AC-174']),
        VfmsHttp::TEST_URL.'service/' => Http::response(['statusCode' => 5000, 'statusMessage' => 'The Room ID 1 Is not Empty']),
    ]);
});

/** A checked-in stay with an accepted check-in, a refused extra and a check-out queued behind it. */
function vfmsPanelStay(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'adults' => 1, 'children' => 0, 'nightly_rate' => 11000, 'room_id' => Room::query()->sole()->id]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Panel Guest', 'documents' => [['type' => 'passport', 'number' => 'PANEL-DOC-174', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), [
            'category' => 'bar', 'description' => 'WATER', 'quantity' => 1, 'unit_price' => 1000, 'idempotency_key' => 'panel-water',
        ]);
        foreach (['2026-12-10', '2026-12-11'] as $date) {
            app(CloseBusinessDate::class)->handle($tenant->owner, $tenant->property->fresh(), $date);
        }

        return app(CheckOut::class)->handle($tenant->owner, $reservation->fresh(), 'Settled at the desk');
    });
}

// protects=the stay lists every VFMS call in order with status, attempts, ZRA number and refusal (#174); fails_when=a call is missing, out of order or mislabelled; no_claim=report tab.
test('the stay lists its ZRA filings with status, number and refusal message', function () {
    $tenant = twoTenants()->a;
    $reservation = vfmsPanelStay($tenant);
    [$checkIn, $extra] = inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get()->all());

    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('vfmsFilings', 3)
        ->where('vfmsFilings.0', ['id' => $checkIn->id, 'call' => 'Check-in', 'status' => 'sandbox', 'attempts' => 1, 'number' => 'AC-174', 'message' => null, 'refileUrl' => null, 'retryUrl' => null])
        ->where('vfmsFilings.1', ['id' => $extra->id, 'call' => 'Extras', 'status' => 'refused', 'attempts' => 1, 'number' => null, 'message' => 'The Room ID 1 Is not Empty',
            'refileUrl' => route('reservations.vfms.refile', [$reservation, $extra]), 'retryUrl' => null])
        ->where('vfmsFilings.2.call', 'Check-out')
        ->where('vfmsFilings.2.status', 'waiting')
        ->where('vfmsFilings.2.attempts', 0));
});

// protects=Re-file and Retry reach only the recovery roles from #173 (#174); fails_when=Reception or Finance get an action URL; no_claim=HTTP refusal of the action (VfmsRecoveryTest).
test('ZRA filing actions appear only for recovery roles', function (OrganizationRole $role) {
    $tenant = twoTenants()->a;
    $reservation = vfmsPanelStay($tenant);
    $actor = $role === OrganizationRole::Owner ? $tenant->owner : $tenant->member($role);
    $allowed = in_array($role, [OrganizationRole::Owner, OrganizationRole::PropertyManager], true);

    $this->actingAs($actor)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertOk()->assertDontSee('PANEL-DOC-174')
        ->assertInertia(fn (Assert $page) => $page
            ->where('vfmsFilings.1.message', 'The Room ID 1 Is not Empty')
            ->where('vfmsFilings.1.refileUrl', $allowed ? route('reservations.vfms.refile', [$reservation, $page->toArray()['props']['vfmsFilings'][1]['id']]) : null));
})->with([
    OrganizationRole::Owner, OrganizationRole::PropertyManager, OrganizationRole::GroupOperationsManager,
    OrganizationRole::FrontDesk, OrganizationRole::Finance, OrganizationRole::Auditor,
]);

// protects=an unknown outcome is never offered re-file or retry, while a stopped delivery can be retried (#173, #174); fails_when=an unknown call gets an action or a retryable one does not; no_claim=reconciliation UI.
test('unknown filings get no action and stopped deliveries offer retry', function () {
    $tenant = twoTenants()->a;
    $reservation = vfmsPanelStay($tenant);
    inTenant($tenant->property, function () {
        [$checkIn, $extra] = OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get()->all();
        $checkIn->forceFill(['status' => 'exhausted_unknown'])->save();
        $extra->forceFill(['status' => 'exhausted'])->save();
    });

    $this->actingAs($tenant->owner)->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertInertia(fn (Assert $page) => $page
        ->where('vfmsFilings.0.status', 'unknown')
        ->where('vfmsFilings.0.refileUrl', null)
        ->where('vfmsFilings.0.retryUrl', null)
        ->where('vfmsFilings.1.status', 'waiting')
        ->where('vfmsFilings.1.message', null)
        ->where('vfmsFilings.1.refileUrl', null)
        ->where('vfmsFilings.1.retryUrl', route('reservations.vfms.retry', [$reservation, $page->toArray()['props']['vfmsFilings'][1]['id']])));
});
