<?php

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam')));

/** @return array{Reservation, Reservation, Guest} */
function documentGuardArrivals(TenantFixture $tenant): array
{
    return inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        $firstRoom = Room::query()->sole();
        $secondRoom = app(CreateRoom::class)->handle($tenant->owner, InventoryForm::room($firstRoom->getAttribute('room_type_id'), ['number' => '202']));
        $stays = [];
        foreach ([$firstRoom, $secondRoom] as $room) {
            $stay = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => $room->id]);
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Guest '.$room->getAttribute('number'), 'documents' => [
                ['type' => 'passport', 'number' => $room->is($firstRoom) ? 'P1234567' : " p 123\t4567\n", 'nationality' => 'DE'],
            ]]);
            ReservationGuest::query()->create(['reservation_id' => $stay->id, 'guest_id' => $guest->id]);
            $stays[] = $stay;
        }
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);

        return [...$stays, $guest];
    });
}

/**
 * protects: one normalized document is in-house once per Zanzibar property, with a useful refusal.
 * fails_when: a second stay checks in, the wrong room is named, or refusal leaks identity/hash data.
 * no_claim: simultaneous database connections or later document corrections.
 */
test('check-in refuses a document already in-house and names the room without leaking its number', function () {
    $t = twoTenants();
    [$first, $second, $guest] = documentGuardArrivals($t->a);
    $this->actingAs($t->a->owner)->postJson(route('reservations.check-in', $first), ['_property' => $t->a->property->id])->assertSessionHasNoErrors();
    $response = $this->postJson(route('reservations.check-in', $second), ['_property' => $t->a->property->id])
        ->assertUnprocessable()->assertJsonValidationErrors('guests');
    expect($response->json('errors.guests.0'))->toContain('101');
    inTenant($t->a->property, function () use ($second, $guest, $response) {
        expect($second->fresh()->status)->toBe(ReservationStatus::Confirmed)
            ->and($second->lifecycleEvents()->where('type', 'checked_in')->exists())->toBeFalse();
        $hash = $guest->documents()->sole()->getAttribute('number_hash');
        expect($response->getContent())->not->toContain('P1234567')->not->toContain($hash);
        $audit = Activity::query()->where('subject_id', $second->id)->where('event', 'refused')->sole();
        expect($audit->toJson())->not->toContain('P1234567')->not->toContain($hash);
    });
});
