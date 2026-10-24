<?php

use App\Domains\FrontDesk\Actions\AddGuest;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
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

/** A stay in room 101 with lead guest Anna Berg (passport P1234567, DE), checked in unless $checkIn is false. */
function guestsVfmsStay(TenantFixture $tenant, bool $checkIn = true): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $checkIn) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        $lead = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Anna Berg', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $lead->id]);

        return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
    });
}

/** @return list<OutboxMessage> VFMS messages after the check-in's accommodation message */
function guestsVfmsMessages(TenantFixture $tenant): array
{
    return inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', '!=', 'accommodation')->orderBy('sequence')->get()->all());
}

// protects=a guest added in house is filed with VFMS against the lead guest (spec #156, guide §2.6); fails_when=no message, a misnamed or mistyped field, the wrong first guest document, or a Sandbox rejection; no_claim=that VFMS production accepts these values.
test('adding a guest to an in-house Sandbox stay files one accepted add-guest message', function () {
    $t = twoTenants();
    $reservation = guestsVfmsStay($t->a);

    inTenant($t->a->property, fn () => app(AddGuest::class)->handle($t->a->owner, $reservation, ['name' => 'Ola Berg', 'documents' => [['type' => 'passport', 'number' => 'P7654321', 'nationality' => 'NO']]]));

    $messages = guestsVfmsMessages($t->a);
    expect($messages)->toHaveCount(1);
    [$message] = $messages;
    $sequence = inTenant($t->a->property, fn () => $reservation->lifecycleEvents()->where('type', 'guest_added')->sole()->sequence);
    expect($message->status)->toBe('delivered')
        ->and($message->event)->toBe('addGuest')
        ->and($message->payload['request_type'])->toBe('ACCOMMODATION_ADD_GUEST')
        ->and($message->payload['path'])->toBe('guest/accommodation/')
        ->and($message->payload['body'])->toBe([
            'firstGuestDocument' => 'P1234567',
            'documentName' => 'passport',
            'documentNo' => 'P7654321',
            'arrivalDate' => '2026-12-10',
            'referenceNumber' => $reservation->reservation_number.'-'.$sequence,
            'country' => 'Norway',
            'fullName' => 'Ola Berg',
        ]);
});
