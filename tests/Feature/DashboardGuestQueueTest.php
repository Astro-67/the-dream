<?php

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Enums\PropertyStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('dashboard guest lists use the property business date and existing reservation list semantics', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['status' => PropertyStatus::Live, 'business_date' => '2026-12-10']);
    $ids = inTenant($property, function () use ($tenants) {
        $seed = app(CreateReservation::class)->handle($tenants->a->owner, BookingForm::details($tenants->a->owner));
        $guest = Guest::query()->create(['name' => 'Nuru Queue Guest']);
        ReservationGuest::query()->create(['reservation_id' => $seed->id, 'guest_id' => $guest->id]);
        $result = [$seed->id];
        foreach ([
            ['checked_in', '2026-12-09', '2026-12-10'],
            ['checked_in', '2026-12-09', '2026-12-12'],
            ['cancelled', '2026-12-10', '2026-12-12'],
        ] as $index => [$status, $arrival, $departure]) {
            $reservation = $seed->replicate();
            $reservation->reservation_number = 'QUEUE-'.$index;
            $reservation->status = $status;
            $reservation->arrival = $arrival;
            $reservation->departure = $departure;
            $reservation->save();
            $result[] = $reservation->id;
        }

        return $result;
    });
    $this->actingAs($tenants->a->member(OrganizationRole::FrontDesk))
        ->withSession(['tenancy.organization_id' => $tenants->a->organization->id, 'tenancy.property_id' => $property->id]);
    foreach (['arrivals' => [$ids[0]], 'departures' => [$ids[1]], 'in-house' => [$ids[1], $ids[2]]] as $list => $expected) {
        $this->get(route('dashboard', ['list' => $list]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('today.businessDate', '2026-12-10')
            ->where('guestQueue.filters.list', $list)
            ->where('guestQueue.reservations.data.0.front_desk_priority', 1)
            ->where('guestQueue.reservations.data', fn ($rows) => $rows->pluck('id')->all() === $expected)
            ->where('today.lists.0.count', 1)
            ->where('today.lists.1.count', 1)
            ->where('today.lists.2.count', 2));
    }
    $this->get(route('dashboard', ['search' => 'Nuru']))->assertInertia(fn (Assert $page) => $page
        ->has('guestQueue.reservations.data', 1)
        ->where('guestQueue.reservations.data.0.guest_name', 'Nuru Queue Guest')
        ->where('guestQueue.reservations.data.0.can_check_in', true)
        ->where('guestQueue.reservations.per_page', 50)
        ->where('today.lists.1.href', route('reservations.index', ['date' => '2026-12-10', 'list' => 'departures', 'search' => 'Nuru'])));
});

test('dashboard guest search and pagination exclude other properties and organizations and do not page rooms', function () {
    $tenants = twoTenants();
    $property = $tenants->a->property;
    $property->update(['status' => PropertyStatus::Live, 'business_date' => '2026-12-10']);
    foreach ([
        [$property, $tenants->a->owner, 'MAIN-000'],
        [$tenants->a->otherProperty, $tenants->a->owner, 'OTHER-PROPERTY'],
        [$tenants->b->property, $tenants->b->owner, 'OTHER-ORGANIZATION'],
    ] as [$scope, $owner, $number]) {
        inTenant($scope, function () use ($scope, $owner, $number, $property) {
            $seed = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
            $seed->update(['reservation_number' => $number]);
            if ($scope->is($property)) {
                foreach (range(1, 50) as $index) {
                    $reservation = $seed->replicate();
                    $reservation->reservation_number = 'MAIN-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
                    $reservation->save();
                }
            }
        });
    }
    $this->actingAs($tenants->a->owner)
        ->withSession(['tenancy.organization_id' => $tenants->a->organization->id, 'tenancy.property_id' => $property->id]);
    $this->get(route('dashboard', ['list' => 'arrivals', 'search' => 'MAIN', 'period' => 'this-month', 'guests_page' => 2]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('guestQueue.reservations.total', 51)
        ->where('guestQueue.reservations.current_page', 2)
        ->has('guestQueue.reservations.data', 1)
        ->where('guestQueue.reservations.data.0.reservation_number', 'MAIN-050')
        ->where('guestQueue.period', 'this-month')
        ->where('roomBoard.rooms.current_page', 1));
    foreach (['OTHER-PROPERTY', 'OTHER-ORGANIZATION'] as $search) {
        $this->get(route('dashboard', ['search' => $search]))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('guestQueue.reservations.data', 0));
    }
});

test('front desk priorities are ordered before pagination without changing the normal reservation list', function () {
    [$owner, , $property] = organizationWithOwner();
    $property->update(['business_date' => '2026-12-10']);
    $overdue = inTenant($property, function () use ($owner) {
        $seed = app(CreateReservation::class)->handle($owner, BookingForm::details($owner));
        $seed->update(['reservation_number' => 'STAY-000', 'status' => 'checked_in', 'arrival' => '2026-12-01']);
        foreach (range(1, 50) as $index) {
            $reservation = $seed->replicate();
            $reservation->reservation_number = 'STAY-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $reservation->save();
        }
        $reservation = $seed->replicate();
        $reservation->reservation_number = 'ZZ-OVERDUE';
        $reservation->arrival = '2026-12-08';
        $reservation->departure = '2026-12-09';
        $reservation->save();

        return $reservation;
    });
    $this->actingAs($owner)->get(route('dashboard', ['list' => 'in-house']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('guestQueue.reservations.total', 52)
        ->where('guestQueue.reservations.data.0.id', $overdue->id)
        ->where('guestQueue.reservations.data.0.front_desk_priority', 0)
        ->where('guestQueue.reservations.data.1.front_desk_priority', 2));
    $this->get(route('dashboard', ['list' => 'in-house', 'guests_page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('guestQueue.reservations.data', 2)
            ->where('guestQueue.reservations.data.0.front_desk_priority', 2));
    $this->get(route('reservations.index', ['list' => 'in-house']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('reservations.data.0.reservation_number', 'STAY-000'));
});

test('dashboard validates guest queue filters at the request boundary', function (array $query, string $error) {
    [$owner] = organizationWithOwner();
    $this->actingAs($owner)->from(route('dashboard'))
        ->get(route('dashboard', $query))->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors($error);
})->with([
    [['list' => 'all'], 'list'],
    [['list' => ['arrivals']], 'list'],
    [['search' => str_repeat('a', 256)], 'search'],
    [['search' => ['guest']], 'search'],
]);
