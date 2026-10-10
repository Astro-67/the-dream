<?php

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
