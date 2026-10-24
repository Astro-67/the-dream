<?php

use App\Domains\FrontDesk\Actions\AddGuest;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Actions\UpdateGuest;
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

/** @param array<string, mixed> $details */
function guestsVfmsUpdateLead(TenantFixture $tenant, array $details): void
{
    inTenant($tenant->property, fn () => app(UpdateGuest::class)->handle($tenant->owner, Guest::query()->where('name', 'Anna Berg')->sole(), $details));
}

// protects=a correction to what VFMS holds about an in-house guest is filed (spec #156, guide §2.7); fails_when=no message, old and new document numbers swapped or missing, or a misnamed field; no_claim=that VFMS production accepts the correction.
test('correcting an in-house guest files one accepted change-profile message', function (array $details, array $expected) {
    $t = twoTenants();
    $reservation = guestsVfmsStay($t->a);

    guestsVfmsUpdateLead($t->a, $details);

    $messages = guestsVfmsMessages($t->a);
    expect($messages)->toHaveCount(1);
    [$message] = $messages;
    $sequence = inTenant($t->a->property, fn () => $reservation->lifecycleEvents()->where('type', 'guest_profile_changed')->sole()->sequence);
    expect($message->status)->toBe('delivered')
        ->and($message->event)->toBe('changeProfile')
        ->and($message->payload['request_type'])->toBe('GUEST_PROFILE')
        ->and($message->payload['path'])->toBe('guest/changeProfile/')
        ->and($message->payload['body'])->toBe([
            'oldDocumentNo' => 'P1234567',
            ...$expected,
            'referenceNumber' => $reservation->reservation_number.'-'.$sequence,
        ]);
})->with([
    'name' => [['name' => 'Anna Berg-Lund'], ['newDocumentNo' => 'P1234567', 'fullName' => 'Anna Berg-Lund', 'documentName' => 'passport', 'country' => 'Germany']],
    'country' => [['documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'SE']]], ['newDocumentNo' => 'P1234567', 'fullName' => 'Anna Berg', 'documentName' => 'passport', 'country' => 'Sweden']],
    'document' => [['documents' => [['type' => 'national-id', 'number' => 'X998877', 'nationality' => 'DE']]], ['newDocumentNo' => 'X998877', 'fullName' => 'Anna Berg', 'documentName' => 'national-id', 'country' => 'Germany']],
]);

// protects=VFMS hears only about stays it holds and fields it holds (spec #156, #162); fails_when=editing a guest not in house, or a phone or email, files a message or records an event; no_claim=corrections after check-out (VFMS error correction, Step 4).
test('editing a guest not in house, or a field VFMS does not hold, files nothing', function (bool $checkIn, array $details) {
    $t = twoTenants();
    $reservation = guestsVfmsStay($t->a, $checkIn);

    guestsVfmsUpdateLead($t->a, $details);

    expect(guestsVfmsMessages($t->a))->toBe([])
        ->and(inTenant($t->a->property, fn () => $reservation->lifecycleEvents()->where('type', 'guest_profile_changed')->exists()))->toBeFalse();
})->with([
    'name before check-in' => [false, ['name' => 'Anna Berg-Lund']],
    'in house: phone and email' => [true, ['phone' => '+255 700 000 000', 'email' => 'anna@example.com']],
    'in house: same document resubmitted' => [true, ['documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]],
]);
