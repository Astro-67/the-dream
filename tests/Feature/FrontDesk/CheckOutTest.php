<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use Carbon\CarbonImmutable;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

/** A guest checked in on 10 December, departing 12 December; the clock is on the departure morning. */
function departingGuestForCheckOut(TenantFixture $tenant): Reservation
{
    test()->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    $reservation = inTenant($tenant->property, function () use ($tenant) {
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...BookingForm::details($tenant->owner), 'room_id' => Room::query()->sole()->id]);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Leaving Guest', 'documents' => [['type' => 'passport', 'number' => 'P7654321', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
    test()->travelTo(CarbonImmutable::parse('2026-12-12 09:00:00', 'Africa/Dar_es_Salaam'));

    return $reservation;
}

/** Posts an extra charge of $amount minor units to the reservation's folio. */
function chargeBeforeCheckOut(TenantFixture $tenant, Reservation $reservation, int $amount): Folio
{
    return inTenant($tenant->property, function () use ($tenant, $reservation, $amount) {
        $folio = Folio::query()->where('reservation_id', $reservation->id)->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'minibar', 'quantity' => 1, 'unit_price' => $amount]);

        return $folio;
    });
}

test('front desk checks out a settled stay, marking the room dirty and recording one event', function () {
    $t = twoTenants();
    $reservation = departingGuestForCheckOut($t->a);
    $folio = chargeBeforeCheckOut($t->a, $reservation, 1500);
    inTenant($t->a->property, fn () => app(RecordPayment::class)->handle($t->a->owner, $folio, ['method' => 'bank_transfer', 'amount' => 1500, 'currency' => 'USD']));
    $actor = $t->a->member(OrganizationRole::FrontDesk);

    $this->actingAs($actor)->post(route('reservations.check-out', $reservation), ['_property' => $t->a->property->id])
        ->assertRedirect(route('reservations.show', $reservation))->assertSessionHasNoErrors();

    inTenant($t->a->property, function () use ($reservation, $actor) {
        expect($reservation->fresh()->status)->toBe(ReservationStatus::CheckedOut)
            ->and(RoomStatus::query()->where('room_id', $reservation->room_id)->sole()->status)->toBe(HousekeepingStatus::Dirty);
        $event = $reservation->lifecycleEvents()->where('type', 'checked_out')->sole();
        expect($event->actor_id)->toBe($actor->id)->and($event->snapshot['status'])->toBe('checked_out');
    });
});
