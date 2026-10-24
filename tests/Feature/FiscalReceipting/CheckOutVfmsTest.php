<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
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

/** Checks the stay out on its departure date; the owner's override covers any balance. */
function checkOutOnDeparture(TenantFixture $tenant, Reservation $reservation): Reservation
{
    $tenant->property->update(['business_date' => '2026-12-12']);

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
