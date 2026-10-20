<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Actions\ReplaceIdentityDocuments;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/**
 * A confirmed reservation arriving today in a clean, assigned room, with one
 * fully registered guest.
 *
 * @param  list<array<string, mixed>>  $documents
 */
function arrivingToday(TenantFixture $tenant, array $documents = [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $documents) {
        $details = BookingForm::details($tenant->owner);
        $room = Room::query()->sole();
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => $room->id]);
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Arriving Guest', 'documents' => $documents]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $reservation;
    });
}

test('front desk checks in a ready arrival, marking the room occupied and recording one event', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])
        ->assertRedirect(route('reservations.show', $reservation));
    inTenant($t->a->property, function () use ($reservation, $actor) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Occupied);
        $event = $reservation->lifecycleEvents()->where('type', 'checked_in')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['status'])->toBe('checked_in');
    });
});

test('today\'s arrivals list offers check-in to those who may check in', function (OrganizationRole $role, bool $offered) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $this->actingAs($t->a->member($role))->get(route('reservations.index', ['list' => 'arrivals']))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.reservation_number', $reservation->reservation_number)->where('reservations.data.0.can_check_in', $offered));
})->with([[OrganizationRole::FrontDesk, true], [OrganizationRole::PropertyManager, true], [OrganizationRole::Finance, false]]);

test('check-in is not offered for another date or a checked-in arrival', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $t->a->property->update(['business_date' => '2026-12-11']);
    $this->get(route('reservations.index', ['list' => 'all']))->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.can_check_in', false));
    $t->a->property->update(['business_date' => '2026-12-10']);
    $this->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id]);
    $this->get(route('reservations.index', ['list' => 'arrivals']))->assertInertia(fn (AssertableInertia $page) => $page->where('reservations.data.0.status', 'checked_in')->where('reservations.data.0.can_check_in', false));
});

test('only reservation managers may check in', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $this->actingAs($t->a->member($role))->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe($allowed ? ReservationStatus::CheckedIn : ReservationStatus::Confirmed));
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk], true)], OrganizationRole::cases()));

test('check-in requires the opening property intent', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $reservation))->assertUnprocessable()->assertJsonValidationErrors('_property');
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed));
});

/** Posts check-in and asserts it was refused on $field with nothing changed. */
function assertCheckInRefused(TenantFixture $tenant, Reservation $reservation, string $field): void
{
    $statuses = fn () => inTenant($tenant->property, fn () => RoomStatus::query()->pluck('status', 'room_id')->all());
    $before = $statuses();
    test()->actingAs($tenant->owner)->postJson(route('reservations.check-in', $reservation), ['_property' => $tenant->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($statuses())->toBe($before);
    inTenant($tenant->property, fn () => expect($reservation->lifecycleEvents()->where('type', 'checked_in')->exists())->toBeFalse());
}

test('check-in is refused for a reservation that is not confirmed', function (ReservationStatus $status) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    inTenant($t->a->property, fn () => $reservation->update(['status' => $status]));
    assertCheckInRefused($t->a, $reservation, 'reservation');
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe($status));
})->with(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::Confirmed));

test('check-in is refused unless the reservation arrives on the stored business date, whatever the local date', function (string $businessDate) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    // The local date stays 10 December; only the stored business date differs.
    $t->a->property->update(['business_date' => $businessDate]);
    assertCheckInRefused($t->a, $reservation, 'reservation');
})->with([
    'business date lagging the local date' => '2026-12-09',
    'business date ahead of the local date' => '2026-12-11',
]);

test('check-in is refused without an assigned room', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    inTenant($t->a->property, fn () => app(AssignRoom::class)->handle($t->a->owner, $reservation, null));
    assertCheckInRefused($t->a, $reservation, 'room');
});

test('check-in is refused while the room is not ready', function (HousekeepingStatus $status) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    inTenant($t->a->property, fn () => RoomStatus::query()->where('room_id', $reservation->room_id)->update(['status' => $status]));
    assertCheckInRefused($t->a, $reservation, 'room');
})->with(array_filter(HousekeepingStatus::cases(), fn ($status) => $status !== HousekeepingStatus::Clean));

test('check-in reads readiness after acquiring the housekeeping room lock', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Room row locks require MySQL.');
    }
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    $enabled = true;
    DB::listen(function (QueryExecuted $query) use (&$enabled, $reservation, $t) {
        if ($enabled && str_contains($query->sql, 'from `rooms`') && str_contains($query->sql, 'for update')) {
            $enabled = false;
            inTenant($t->a->property, fn () => RoomStatus::query()->where('room_id', $reservation->room_id)->update(['status' => HousekeepingStatus::Dirty]));
        }
    });
    try {
        $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])
            ->assertUnprocessable()->assertJsonValidationErrors('room');
        inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::Confirmed));
    } finally {
        $enabled = false;
    }
});

test('at a Zanzibar property check-in is refused while any guest misses an identity field', function (array $documents) {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    inTenant($t->a->property, function () use ($t, $reservation, $documents) {
        $guest = app(CreateGuest::class)->handle($t->a->owner, ['name' => 'Second Guest', 'documents' => $documents]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
    });
    assertCheckInRefused($t->a, $reservation, 'guests');
})->with([
    'no document' => [[]],
    'no nationality' => [[['type' => 'passport', 'number' => 'P7654321']]],
    'no number' => [[['type' => 'national_id', 'number' => '', 'nationality' => 'TZ']]],
]);

test('at a Zanzibar property check-in is refused with no registered guest', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a);
    inTenant($t->a->property, fn () => ReservationGuest::query()->delete());
    assertCheckInRefused($t->a, $reservation, 'guests');
});

test('at a mainland property only the configured registration fields are enforced', function () {
    $t = twoTenants();
    $t->a->property->update(['jurisdiction_profile' => JurisdictionProfile::MainlandTanzania, 'zra_hotel_code' => null, 'registration_fields' => ['number']]);
    $reservation = arrivingToday($t->a, [['type' => 'passport', 'number' => '']]);
    assertCheckInRefused($t->a, $reservation, 'guests');
    inTenant($t->a->property, fn () => app(ReplaceIdentityDocuments::class)->handle(Guest::query()->sole(), [['type' => 'passport', 'number' => 'P1234567']]));
    $this->actingAs($t->a->owner)->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedIn));
});

test('foreign guest needs a passport at check-in when the property requires it', function () {
    $t = twoTenants();
    $reservation = arrivingToday($t->a, [['type' => 'nida', 'number' => '12345', 'nationality' => 'DE']]);
    assertCheckInRefused($t->a, $reservation, 'guests');
    $t->a->property->update(['front_desk_settings' => ['passport_required_for_foreign_guests' => false]]);
    $this->actingAs($t->a->owner)->post(route('reservations.check-in', $reservation), ['_property' => $t->a->property->id])->assertRedirect()->assertSessionHasNoErrors();
});
