<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\ExtendStay;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\TakeRoomOutOfService;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/** A guest checked in today for 10–12 December, with prices set through 14 December. */
function inHouseStay(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        app(SetRatePrices::class)->handle($tenant->owner, RatePlan::query()->sole(), ['room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-14', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 20000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'adults' => 1, 'children' => 0, 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Staying Guest', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

/**
 * Books another stay of the in-house reservation's room type and plan.
 *
 * @param  array<string, mixed>  $overrides
 */
function competingBooking(TenantFixture $tenant, Reservation $reservation, array $overrides): Reservation
{
    return inTenant($tenant->property, fn () => app(CreateReservation::class)->handle($tenant->owner, [...$reservation->only(['room_type_id', 'rate_plan_id', 'booker_id', 'adults', 'children', 'source']), ...$overrides]));
}

/** Posts an extension to $departure and asserts it was refused on $field with nothing changed. */
function assertExtensionRefused(TenantFixture $tenant, Reservation $reservation, string $departure, string $field): void
{
    $state = fn () => inTenant($tenant->property, fn () => [
        $reservation->fresh()->departure->toDateString(),
        InventoryRecord::query()->orderBy('room_type_id')->orderBy('date')->pluck('sold_count')->all(),
        RoomAssignmentNight::query()->count(),
        $reservation->priceLines()->count(),
        $reservation->lifecycleEvents()->count(),
    ]);
    $before = $state();
    test()->actingAs($tenant->owner)->postJson(route('reservations.extend', $reservation), ['_property' => $tenant->property->id, 'departure' => $departure])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($state())->toBe($before);
}

/** @return list<string> */
function assignedNights(Reservation $reservation): array
{
    return RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->orderBy('date')->get()->map(fn ($night) => $night->date->toDateString())->all();
}

test('front desk extends an in-house stay, taking inventory, the room and priced nights', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.extend', $reservation), ['_property' => $t->a->property->id, 'departure' => '2026-12-14'])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();
    inTenant($t->a->property, function () use ($reservation, $actor) {
        $reservation = $reservation->fresh();
        expect($reservation->departure->toDateString())->toBe('2026-12-14')
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-13'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1, 1, 1])
            ->and(assignedNights($reservation))->toBe(['2026-12-10', '2026-12-11', '2026-12-12', '2026-12-13'])
            ->and($reservation->priceLines()->orderBy('date')->get()->map(fn ($line) => [$line->date->toDateString(), $line->amount])->all())->toBe([['2026-12-10', 10000], ['2026-12-11', 10000], ['2026-12-12', 20000], ['2026-12-13', 20000]]);
        $event = $reservation->lifecycleEvents()->where('type', 'extended')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['previous_departure'])->toBe('2026-12-12');
    });
});

test('an extension is refused when no inventory is left for a new night', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    competingBooking($t->a, $reservation, ['arrival' => '2026-12-13', 'departure' => '2026-12-14']);
    assertExtensionRefused($t->a, $reservation, '2026-12-14', 'departure');
});

test('an extension is refused when the assigned room is taken on a new night', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    inTenant($t->a->property, fn () => app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($reservation->room_type_id, ['number' => '102'])));
    competingBooking($t->a, $reservation, ['arrival' => '2026-12-13', 'departure' => '2026-12-14', 'room_id' => $reservation->room_id]);
    assertExtensionRefused($t->a, $reservation, '2026-12-14', 'departure');
});

test('an extension is refused while the assigned room is out of service on a new night', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    inTenant($t->a->property, function () use ($t, $reservation) {
        app(CreateRoom::class)->handle($t->a->owner, InventoryForm::room($reservation->room_type_id, ['number' => '102']));
        app(TakeRoomOutOfService::class)->handle($t->a->owner, Room::query()->findOrFail($reservation->room_id), ['starts_on' => '2026-12-13', 'returns_on' => '2026-12-14', 'reason' => 'Leak']);
    });
    assertExtensionRefused($t->a, $reservation, '2026-12-14', 'departure');
});

test('an extension is refused without a price for every new night', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    assertExtensionRefused($t->a, $reservation, '2026-12-16', 'departure');
});

test('an extension must depart after the current departure', function (string $departure) {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    assertExtensionRefused($t->a, $reservation, $departure, 'departure');
})->with(['2026-12-12', '2026-12-11']);

test('only an in-house reservation can be extended', function (ReservationStatus $status) {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    inTenant($t->a->property, fn () => $reservation->update(['status' => $status]));
    assertExtensionRefused($t->a, $reservation, '2026-12-14', 'reservation');
})->with(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::CheckedIn));

/** In house 10–14 December, now on the morning of the 12th. */
function stayingFourNights(TenantFixture $tenant): Reservation
{
    $reservation = inHouseStay($tenant);
    inTenant($tenant->property, fn () => app(ExtendStay::class)->handle($tenant->owner, $reservation, '2026-12-14'));
    $tenant->property->update(['business_date' => '2026-12-12']);

    return $reservation;
}

test('front desk records an early departure, releasing the remaining nights from today', function () {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.depart-early', $reservation), ['_property' => $t->a->property->id])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();
    inTenant($t->a->property, function () use ($reservation, $actor) {
        $reservation = $reservation->fresh();
        expect($reservation->departure->toDateString())->toBe('2026-12-12')
            ->and($reservation->status)->toBe(ReservationStatus::CheckedIn)
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-13'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1, 0, 0])
            ->and(assignedNights($reservation))->toBe(['2026-12-10', '2026-12-11'])
            ->and($reservation->priceLines()->get()->map(fn ($line) => $line->date->toDateString())->all())->toBe(['2026-12-10', '2026-12-11'])
            ->and(RoomAssignmentNight::query()->count())->toBe(2);
        $event = $reservation->lifecycleEvents()->where('type', 'departed_early')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['previous_departure'])->toBe('2026-12-14');
    });
});

/** Posts an early departure and asserts it was refused with nothing changed. */
function assertEarlyDepartureRefused(TenantFixture $tenant, Reservation $reservation): void
{
    $state = fn () => inTenant($tenant->property, fn () => [
        $reservation->fresh()->departure->toDateString(),
        InventoryRecord::query()->orderBy('date')->pluck('sold_count')->all(),
        RoomAssignmentNight::query()->count(),
        $reservation->priceLines()->count(),
        $reservation->lifecycleEvents()->count(),
    ]);
    $before = $state();
    test()->actingAs($tenant->owner)->postJson(route('reservations.depart-early', $reservation), ['_property' => $tenant->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('reservation');
    expect($state())->toBe($before);
}

test('an early departure is refused before arrival or once the stay ends today', function (string $businessDate) {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    $t->a->property->update(['business_date' => $businessDate]);
    assertEarlyDepartureRefused($t->a, $reservation);
})->with(['before arrival' => '2026-12-09', 'departure day' => '2026-12-14']);

test('arrival-day early departure releases every night and allows check-out', function () {
    $t = twoTenants();
    $reservation = inHouseStay($t->a);
    $this->actingAs($t->a->owner)->post(route('reservations.depart-early', $reservation), ['_property' => $t->a->property->id])
        ->assertSessionHasNoErrors();
    inTenant($t->a->property, function () use ($reservation) {
        expect($reservation->fresh()->departure->toDateString())->toBe('2026-12-10')
            ->and($reservation->priceLines()->count())->toBe(0)
            ->and(assignedNights($reservation))->toBe([])
            ->and(InventoryRecord::query()->sum('sold_count'))->toBe(0)
            ->and($reservation->lifecycleEvents()->where('type', 'departed_early')->count())->toBe(1);
    });
    $this->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    inTenant($t->a->property, fn () => expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut));
});

test('only an in-house reservation can depart early', function (ReservationStatus $status) {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    inTenant($t->a->property, fn () => $reservation->update(['status' => $status]));
    assertEarlyDepartureRefused($t->a, $reservation);
})->with(array_filter(ReservationStatus::cases(), fn ($status) => $status !== ReservationStatus::CheckedIn));

test('only reservation managers may extend or record an early departure', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    $member = $t->a->member($role);
    $this->actingAs($member)->post(route('reservations.extend', $reservation), ['_property' => $t->a->property->id, 'departure' => '2026-12-15'])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->departure->toDateString())->toBe($allowed ? '2026-12-15' : '2026-12-14'));
    $this->actingAs($member)->post(route('reservations.depart-early', $reservation), ['_property' => $t->a->property->id])->assertStatus($allowed ? 302 : 403);
    inTenant($t->a->property, fn () => expect($reservation->fresh()->departure->toDateString())->toBe($allowed ? '2026-12-12' : '2026-12-14'));
})->with(array_map(fn ($role) => [$role, in_array($role, [OrganizationRole::Owner, OrganizationRole::GroupOperationsManager, OrganizationRole::PropertyManager, OrganizationRole::FrontDesk], true)], OrganizationRole::cases()));

test('the reservation page offers stay changes to those who may make them', function (OrganizationRole $role, bool $offered) {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->member($role))->get(route('reservations.show', $reservation))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.changeStay', $offered));
})->with([[OrganizationRole::FrontDesk, true], [OrganizationRole::Finance, false]]);

test('stay changes require the opening property intent', function (string $route) {
    $t = twoTenants();
    $reservation = stayingFourNights($t->a);
    $this->actingAs($t->a->owner)->postJson(route($route, $reservation), ['departure' => '2026-12-15'])->assertUnprocessable()->assertJsonValidationErrors('_property');
    inTenant($t->a->property, fn () => expect($reservation->fresh()->departure->toDateString())->toBe('2026-12-14'));
})->with(['reservations.extend', 'reservations.depart-early']);
