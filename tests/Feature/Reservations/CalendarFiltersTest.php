<?php

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

test('calendar and list filters select matching stays and derive overstays from the business date', function (string $filter, array $expected) {
    $t = twoTenants();
    inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $room = Room::query()->sole();
        $seed = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => $room->id]);
        $seed->update(['status' => ReservationStatus::Void]);
        $documented = Guest::query()->create(['name' => 'Documented seed guest']);
        $documented->documents()->create(['type' => 'passport', 'number' => 'P123']);
        ReservationGuest::query()->create(['reservation_id' => $seed->id, 'guest_id' => $documented->id]);
        foreach ([
            'ARRIVING' => ['2026-12-12', '2026-12-14', ReservationStatus::Confirmed],
            'DEPARTING' => ['2026-12-10', '2026-12-12', ReservationStatus::CheckedIn],
            'INHOUSE' => ['2026-12-11', '2026-12-14', ReservationStatus::CheckedIn],
            'OVERSTAY' => ['2026-12-09', '2026-12-11', ReservationStatus::CheckedIn],
            'DEPARTED' => ['2026-12-09', '2026-12-11', ReservationStatus::CheckedOut],
        ] as $number => [$arrival, $departure, $status]) {
            $reservation = $seed->replicate();
            $reservation->fill(['reservation_number' => $number, 'arrival' => $arrival, 'departure' => $departure, 'status' => $status]);
            $reservation->save();
            $guest = Guest::query()->create(['name' => $number]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
            if ($number !== 'INHOUSE') {
                $guest->documents()->create(['type' => 'passport', 'number' => 'P123']);
            }
            if (in_array($number, ['OVERSTAY', 'DEPARTED'])) {
                $folio = app(OpenFolio::class)->handle($t->a->owner, $reservation, 'USD');
                app(PostExtraCharge::class)->handle($t->a->owner, $folio, ['category' => 'other', 'quantity' => 1, 'unit_price' => 500]);
            }
        }
    });
    $t->a->property->update(['business_date' => '2026-12-12']);
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])->actingAs($t->a->owner);
    $assertRows = function ($rows) use ($expected) {
        expect(collect($rows)->pluck('reservation_number')->sort()->values()->all())->toBe($expected);
        foreach ($rows as $row) {
            expect($row['is_overstay'])->toBe($row['reservation_number'] === 'OVERSTAY');
        }

        return true;
    };
    $this->get(route('reservations.index', ['list' => $filter]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('reservations.data', $assertRows));
    $this->get(route('rate-calendar', ['from' => '2026-12-09', 'to' => '2026-12-14', 'list' => $filter]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('roomTypes.0.rooms.0.reservations', $assertRows));
    $this->get(route('rate-calendar', ['from' => '2026-12-12', 'to' => '2026-12-14', 'list' => 'overstays']))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('roomTypes.0.rooms.0.reservations', 1)
        ->where('roomTypes.0.rooms.0.reservations.0.display_departure', '2026-12-13')
        ->where('roomTypes.0.rooms.0.reservations.0.departure', '2026-12-11'));
})->with([
    'arrivals' => ['arrivals', ['ARRIVING']],
    'departures' => ['departures', ['DEPARTING']],
    'in house includes departures and overstays' => ['in-house', ['DEPARTING', 'INHOUSE', 'OVERSTAY']],
    'overstays' => ['overstays', ['OVERSTAY']],
    'balance owing includes departed stays' => ['balance-owing', ['DEPARTED', 'OVERSTAY']],
    'missing document' => ['no-id-document', ['INHOUSE']],
]);
