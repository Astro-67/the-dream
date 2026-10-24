<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
});

// protects=spec #156 AC 5: a full Sandbox stay driven through the real routes files every step with VFMS in order and ends in a stamped report matching the folio (#166); fails_when=a step files no message, an extra one, out of order or unaccepted, any request leaves the server, or the report differs from the folio; no_claim=VFMS production acceptance or Live figures.
// Spec #156 AC 2: fails_when=the property's token (a marker) appears in any outbox payload or attempt body of the stay's VFMS calls.
// The report also lists only guests still in-house: fails_when=a departed guest (Ola) is named on it.
test('a full Sandbox stay files six accepted VFMS messages in order and a stamped report matching the folio', function () {
    $t = twoTenants()->a;
    $property = ['_property' => $t->property->id];
    $marker = 'tok-MARKER-7f3a91';
    $reservation = inTenant($t->property, function () use ($t, $marker) {
        VfmsCredential::query()->create(['integration_id' => 'INT-1001', 'registration_id' => 'REG-2002', 'token' => $marker]);
        $details = BookingForm::details($t->owner);
        app(CreateRoom::class)->handle($t->owner, InventoryForm::room($details['room_type_id'], ['number' => '102']));
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($t->owner, [...$details, 'room_id' => Room::query()->where('number', '101')->sole()->id]);
        foreach ([['Anna Berg', 'P1234567'], ['Ola Berg', 'P7654321']] as [$name, $number]) {
            $guest = app(CreateGuest::class)->handle($t->owner, ['name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }
        // The second night is dearer, so closing the first night files a price change.
        $reservation->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 12500]);

        return $reservation;
    });
    [$room102, $folio, $ola] = inTenant($t->property, fn () => [Room::query()->where('number', '102')->sole(), Folio::query()->sole(), Guest::query()->where('name', 'Ola Berg')->sole()]);
    $desk = $t->member(OrganizationRole::FrontDesk);
    $manager = $t->member(OrganizationRole::PropertyManager);

    $this->actingAs($desk)->post(route('reservations.check-in', $reservation), $property)->assertSessionHasNoErrors();
    $this->post(route('reservations.room-move', $reservation), [...$property, 'room_id' => $room102->id])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('night-audit.store'), [...$property, 'business_date' => '2026-12-10'])->assertSessionHasNoErrors();
    $this->actingAs($desk)->post(route('folios.charges.store', $folio), [...$property, 'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-1'])->assertSessionHasNoErrors();
    $this->post(route('reservations.guest-leaves', $reservation), [...$property, 'guest_id' => $ola->id])->assertSessionHasNoErrors();
    $this->actingAs($manager)->post(route('night-audit.store'), [...$property, 'business_date' => '2026-12-11'])->assertSessionHasNoErrors();
    $this->actingAs($t->owner)->post(route('reservations.check-out', $reservation), [...$property, 'override_reason' => 'Guest settles by bank transfer'])->assertSessionHasNoErrors();

    $messages = inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get());
    expect($messages->pluck('event')->all())->toBe(['accommodation', 'changeRoom', 'changeRoomPrice', 'services', 'guestDeparture', 'checkout'])
        ->and($messages->pluck('status')->unique()->all())->toBe(['delivered'])
        ->and($messages->pluck('stream_key')->unique())->toHaveCount(1)
        ->and($messages[4]->payload['body']['documentNumberList'])->toBe([['documentNumber' => 'P7654321']])
        ->and($messages[5]->payload['body']['documentNumberList'])->toBe([['documentNumber' => 'P1234567']]);
    Http::assertNothingSent();
    $attempts = inTenant($t->property, fn () => $messages->flatMap(fn (OutboxMessage $message) => $message->attempts()->get()));
    expect($attempts)->toHaveCount(6)
        ->and(json_encode([$messages->pluck('payload'), $attempts->map->only(['request_body', 'response_body', 'provider_references'])]))->not->toContain($marker);

    // Nights at 110.00 and 125.00, and 20.00 of water.
    $this->actingAs($desk)->get(route('reservations.fiscal-report', $reservation))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('fiscal-receipting/checkout-report')
            ->where('status', 'sandbox')->where('qrSvg', null)->where('guests', ['Anna Berg'])
            ->where('report.number', fn (string $number) => str_starts_with($number, 'SBX-'))
            ->where('report.room', 23500)->where('report.extras', 2000)->where('report.total', 25500));
    $this->get(route('reservations.show', $reservation))
        ->assertInertia(fn (Assert $page) => $page->where('taxSource', 'vfms')->where('totals.0.charges', 25500)->where('totals.0.balance', 25500));
});
