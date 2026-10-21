<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('front desk lists arrivals departures and in-house reservations on the property local business date', function () {
    $t = twoTenants();
    $t->a->property->update(['timezone' => 'Africa/Dar_es_Salaam']);
    $reservations = inTenant($t->a->property, function () use ($t) {
        $result = [];
        $seed = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        foreach ([
            ['2026-12-11', '2026-12-13', ReservationStatus::Confirmed],
            ['2026-12-09', '2026-12-11', ReservationStatus::CheckedIn],
            ['2026-12-10', '2026-12-12', ReservationStatus::CheckedIn],
            ['2026-12-11', '2026-12-13', ReservationStatus::Cancelled],
        ] as [$arrival, $departure, $status]) {
            $reservation = $seed->replicate();
            $reservation->reservation_number = 'LIST-'.count($result);
            $reservation->save();
            $reservation->update(compact('arrival', 'departure', 'status'));
            $result[] = $reservation;
        }

        $seed->update(['status' => ReservationStatus::Void]);

        return $result;
    });
    $t->a->property->update(['business_date' => '2026-12-11']);
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    foreach (['arrivals' => 0, 'departures' => 1, 'in-house' => 2] as $list => $index) {
        $this->get(route('reservations.index', ['list' => $list]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reservations/index')->where('filters.date', '2026-12-11')
            ->where('filters.list', $list)->has('reservations.data', $list === 'in-house' ? 2 : 1)
            ->where('reservations.data.0.reservation_number', $reservations[$list === 'in-house' ? 1 : $index]->reservation_number)
            ->where('reservations.per_page', 50));
    }
});

test('lookup matches reservation numbers and guest names only within the selected property', function () {
    $t = twoTenants();
    $reservation = inTenant($t->a->property, function () use ($t) {
        $reservation = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $guest = Guest::query()->create(['name' => 'Amina Mussa']);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        $other = $reservation->replicate();
        $other->reservation_number = 'UNRELATED';
        $other->save();

        return $reservation;
    });
    $foreign = inTenant($t->a->otherProperty, fn () => tap(app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)), fn ($r) => $r->update(['reservation_number' => 'OTHER-000001'])));
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    foreach ([$reservation->reservation_number, 'Amina', 'Mussa'] as $search) {
        $this->get(route('reservations.index', ['list' => 'all', 'search' => $search]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('reservations.data', 1)->where('reservations.data.0.id', $reservation->id));
    }
    $this->get(route('reservations.index', ['list' => 'all', 'search' => $foreign->reservation_number]))->assertOk()->assertInertia(fn (Assert $page) => $page->has('reservations.data', 0));
});

test('reservation lists paginate fifty rows preserve filters and allow an explicit business date', function () {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $seed = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        for ($i = 1; $i <= 50; $i++) {
            $copy = $seed->replicate();
            $copy->reservation_number = 'PAGE-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $copy->save();
        }
    });
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $this->get(route('reservations.index', ['list' => 'arrivals', 'date' => '2026-12-10', 'page' => 2]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('filters.date', '2026-12-10')->where('reservations.total', 51)
        ->has('reservations.data', 1)->where('reservations.current_page', 2)
        ->where('reservations.prev_page_url', fn ($url) => str_contains($url, 'list=arrivals') && str_contains($url, 'date=2026-12-10')));
    $this->getJson(route('reservations.index', ['date' => 'not-a-date', 'list' => 'unknown', 'search' => ['invalid']]))->assertUnprocessable()->assertJsonValidationErrors(['date', 'list', 'search']);
});

test('reservation list permission follows the role', function (OrganizationRole $role, bool $allowed) {
    $t = twoTenants();
    $this->actingAs($t->a->member($role))->get(route('reservations.index'))->assertStatus($allowed ? 200 : 403);
})->with([
    [OrganizationRole::Owner, true], [OrganizationRole::GroupOperationsManager, true],
    [OrganizationRole::PropertyManager, true], [OrganizationRole::FrontDesk, true],
    [OrganizationRole::Housekeeping, false],
]);

test('booking tabs use the selected business date and preserve search and property isolation', function () {
    $t = twoTenants();
    $records = inTenant($t->a->property, function () use ($t) {
        $seed = app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner));
        $records = [];
        foreach ([
            ['UPCOMING', '2026-12-12', '2026-12-14', ReservationStatus::Confirmed],
            ['TODAY', '2026-12-11', '2026-12-13', ReservationStatus::Confirmed],
            ['IN-HOUSE', '2026-12-11', '2026-12-13', ReservationStatus::CheckedIn],
            ['OVERSTAY', '2026-12-08', '2026-12-10', ReservationStatus::CheckedIn],
            ['MISSED', '2026-12-10', '2026-12-12', ReservationStatus::Confirmed],
            ['CHECKED-OUT', '2026-12-09', '2026-12-11', ReservationStatus::CheckedOut],
            ['CANCELLED', '2026-12-12', '2026-12-14', ReservationStatus::Cancelled],
            ['NO-SHOW', '2026-12-10', '2026-12-12', ReservationStatus::NoShow],
        ] as [$number, $arrival, $departure, $status]) {
            $copy = $seed->replicate();
            $copy->reservation_number = 'TABS-'.$number;
            $copy->save();
            $copy->update(compact('arrival', 'departure', 'status'));
            $records[$number] = $copy->id;
        }
        $seed->update(['reservation_number' => 'TABS-VOID', 'status' => ReservationStatus::Void]);
        $records['VOID'] = $seed->id;

        return $records;
    });
    foreach ([$t->a->otherProperty, $t->b->property] as $property) {
        $owner = $property->organization_id === $t->a->organization->id ? $t->a->owner : $t->b->owner;
        inTenant($property, function () use ($owner) {
            $foreign = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $foreign->update(['reservation_number' => 'TABS-FOREIGN', 'arrival' => '2026-12-12', 'departure' => '2026-12-14']);
        });
    }
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $t->a->property->update(['business_date' => '2026-12-11']);
    foreach ([
        'upcoming' => ['UPCOMING'],
        'today' => ['TODAY', 'IN-HOUSE'],
        'in_house' => ['OVERSTAY', 'IN-HOUSE'],
        'past' => ['CHECKED-OUT', 'CANCELLED', 'NO-SHOW', 'VOID'],
        'all' => array_keys($records),
    ] as $list => $keys) {
        $expected = array_map(fn ($key) => $records[$key], $keys);
        $this->get(route('reservations.index', ['list' => $list, 'search' => 'TABS-']))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('filters.list', $list)->where('filters.date', '2026-12-11')
            ->where('reservations.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect($expected)->sort()->values()->all()));
    }
    $this->get(route('reservations.index'))->assertInertia(fn (Assert $page) => $page->where('filters.list', 'upcoming')->where('canCreate', true));
    $this->get(route('reservations.index', ['list' => 'upcoming', 'date' => '2026-12-10', 'search' => 'TABS-TODAY']))
        ->assertInertia(fn (Assert $page) => $page->has('reservations.data', 1)->where('reservations.data.0.id', $records['TODAY']));
    $this->actingAs($t->a->member(OrganizationRole::Finance))->get(route('reservations.index'))
        ->assertInertia(fn (Assert $page) => $page->where('canCreate', false));
});
