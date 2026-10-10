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
    $this->travelTo(now('UTC')->setDate(2026, 12, 10)->setTime(22, 30));
    $this->actingAs($t->a->member(OrganizationRole::FrontDesk));
    foreach (['arrivals' => 0, 'departures' => 1, 'in-house' => 2] as $list => $index) {
        $this->get(route('reservations.index', ['list' => $list]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('reservations/index')->where('filters.date', '2026-12-11')
            ->where('filters.list', $list)->has('reservations.data', 1)
            ->where('reservations.data.0.reservation_number', $reservations[$index]->reservation_number)
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
    $foreign = inTenant($t->a->otherProperty, fn () => app(CreateReservation::class)->handle($t->a->owner, BookingForm::details($t->a->owner)));
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
