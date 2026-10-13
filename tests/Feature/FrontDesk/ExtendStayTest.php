<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\InventoryRecord;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\Models\RoomAssignmentNight;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/** A guest checked in today for 10–12 December, with prices set through 14 December. */
function inHouse(TenantFixture $tenant): Reservation
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        app(SetRatePrices::class)->handle($tenant->owner, RatePlan::query()->sole(), ['room_type_id' => $details['room_type_id'], 'from' => '2026-12-12', 'to' => '2026-12-14', 'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 20000, 'extra_adult' => 2500, 'extra_child' => 1000]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Staying Guest', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

/** @return list<string> */
function assignedNights(Reservation $reservation): array
{
    return RoomAssignmentNight::query()->where('reservation_id', $reservation->id)->orderBy('date')->get()->map(fn ($night) => $night->date->toDateString())->all();
}

test('front desk extends an in-house stay, taking inventory, the room and priced nights', function () {
    $t = twoTenants();
    $reservation = inHouse($t->a);
    $actor = $t->a->member(OrganizationRole::FrontDesk);
    $this->actingAs($actor)->post(route('reservations.extend', $reservation), ['_property' => $t->a->property->id, 'departure' => '2026-12-14'])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();
    inTenant($t->a->property, function () use ($reservation, $actor) {
        $reservation = $reservation->fresh();
        expect($reservation->departure->toDateString())->toBe('2026-12-14')
            ->and(InventoryRecord::query()->whereBetween('date', ['2026-12-10', '2026-12-13'])->orderBy('date')->pluck('sold_count')->all())->toBe([1, 1, 1, 1])
            ->and(assignedNights($reservation))->toBe(['2026-12-10', '2026-12-11', '2026-12-12', '2026-12-13'])
            // 2 adults and 1 child in a base-2 room: base price plus one child.
            ->and($reservation->priceLines()->orderBy('date')->get()->map(fn ($line) => [$line->date->toDateString(), $line->amount])->all())->toBe([['2026-12-10', 11000], ['2026-12-11', 11000], ['2026-12-12', 21000], ['2026-12-13', 21000]]);
        $event = $reservation->lifecycleEvents()->where('type', 'extended')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['previous_departure'])->toBe('2026-12-12');
    });
});
