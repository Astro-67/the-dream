<?php

use App\Domains\FiscalReceipting\Adapters\SandboxVfms;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Actions\MoveRoom;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Actions\SetRatePrices;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Rates\Models\TaxRule;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingForm;
use Tests\Support\InventoryForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    $this->tenant = TenantFixture::build('RoomChanges');
    $this->tenant->property->update(['business_date' => '2026-12-10']);
    $this->reservation = inTenant($this->tenant->property, function () {
        $details = BookingForm::details($this->tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($this->tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast']);
        foreach (['LEAD-161', 'SECOND-161'] as $number) {
            $guest = app(CreateGuest::class)->handle($this->tenant->owner, ['name' => $number, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => 'DE']]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }

        return app(CheckIn::class)->handle($this->tenant->owner, $reservation);
    });
});

// protects=guide §2.3 files the next night's changed price after accommodation; fails_when=the current night's price, wrong room or duplicate message is filed; no_claim=production VFMS acceptance.
test('night audit files a changed next-night price once on the stay stream', function (string $currency, float $price) {
    inTenant($this->tenant->property, function () use ($currency, $price) {
        $this->reservation->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 12550, 'currency' => $currency]);
        app(CloseBusinessDate::class)->handle($this->tenant->owner, $this->tenant->property, '2026-12-10');
        app(CloseBusinessDate::class)->handle($this->tenant->owner, $this->tenant->property, '2026-12-10');

        $messages = OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get();
        $room = Room::query()->findOrFail($this->reservation->room_id);
        expect($messages->pluck('event')->all())->toBe(['accommodation', 'changeRoomPrice'])
            ->and($messages->pluck('status')->all())->toBe(['delivered', 'delivered'])
            ->and($messages->pluck('stream_key')->unique())->toHaveCount(1)
            ->and($messages->pluck('sequence')->all())->toBe([1, 2])
            ->and($messages[1]->payload['request_type'])->toBe('CHANGEROOM_PRICE')
            ->and($messages[1]->payload['path'])->toBe('changeRoomPrice/')
            ->and($messages[1]->payload['body'])->toEqual([
                'rooms' => [['roomPrice' => $price, 'hotelRoomRoom' => $room->vfms_room_id]],
            ]);
    });
    Http::assertNothingSent();
})->with(['USD' => ['USD', 125.5], 'EUR' => ['EUR', 125.5], 'TZS' => ['TZS', 12550.0]]);

// protects=guide §2.2 room moves follow check-in on the stay stream; fails_when=the destination, lead document, price or order is wrong; no_claim=production VFMS acceptance.
test('a Sandbox room move files the new room and lead document after check-in', function (string $currency, string $vfmsCurrency, float $price) {
    inTenant($this->tenant->property, function () use ($currency, $vfmsCurrency, $price) {
        $this->reservation->priceLines()->update(['currency' => $currency]);
        $room = app(CreateRoom::class)->handle($this->tenant->owner, InventoryForm::room($this->reservation->room_type_id, ['number' => '202']));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        app(MoveRoom::class)->handle($this->tenant->owner, $this->reservation, $room->id);

        $messages = OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get();
        expect($messages->pluck('event')->all())->toBe(['accommodation', 'changeRoom'])
            ->and($messages->pluck('status')->all())->toBe(['delivered', 'delivered'])
            ->and($messages->pluck('stream_key')->unique())->toHaveCount(1)
            ->and($messages->pluck('sequence')->all())->toBe([1, 2])
            ->and($messages[1]->payload['request_type'])->toBe('ACCOMMODATION_CHANGE_ROOM')
            ->and($messages[1]->payload['path'])->toBe('change/room/')
            ->and($messages[1]->payload['body'])->toEqual([
                'roomName' => '202',
                'roomId' => $room->vfms_room_id,
                'documentNumber' => 'LEAD-161',
                'referenceNumber' => $this->reservation->reservation_number.'-3',
                'currency' => $vfmsCurrency,
                'accommodationPrice' => $price,
                'accommodationDiscount' => 0,
            ]);
    });
    Http::assertNothingSent();
})->with(['USD' => ['USD', 'USD', 110.0], 'EUR' => ['EUR', 'EURO', 110.0], 'TZS' => ['TZS', 'TZS', 11000.0]]);

// protects=Sandbox validates guide §2.3 room entries; fails_when=a room without its price or VFMS number is accepted; no_claim=production VFMS validation.
test('Sandbox rejects missing fields within a changed room price', function (string $field) {
    inTenant($this->tenant->property, function () use ($field) {
        $this->reservation->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 12550]);
        app(CloseBusinessDate::class)->handle($this->tenant->owner, $this->tenant->property, '2026-12-10');
        $payload = OutboxMessage::query()->where('integration', 'vfms')->where('event', 'changeRoomPrice')->sole()->payload;
        data_forget($payload['body'], 'rooms.0.'.$field);

        $result = app(SandboxVfms::class)->send($payload, new VfmsCredential);

        expect($result->outcome)->toBe('rejected')
            ->and($result->responseBody['missing'])->toContain('rooms.0.'.$field);
    });
})->with(['roomPrice', 'hotelRoomRoom']);

// protects=an unchanged nightly price produces no fiscal call; fails_when=closing a steady-price stay files a price change; no_claim=changes outside night audit.
test('night audit files nothing when the next-night price is unchanged', function () {
    inTenant($this->tenant->property, function () {
        app(CloseBusinessDate::class)->handle($this->tenant->owner, $this->tenant->property, '2026-12-10');

        expect(OutboxMessage::query()->where('integration', 'vfms')->pluck('event')->all())->toBe(['accommodation']);
    });
    Http::assertNothingSent();
});

// protects=a repriced room move files the new price, not the check-in price; fails_when=VFMS keeps the old rate after a reprice; no_claim=rate editing permissions.
test('a repriced room move files the destination price', function () {
    inTenant($this->tenant->property, function () {
        $room = app(CreateRoom::class)->handle($this->tenant->owner, InventoryForm::room($this->reservation->room_type_id, ['number' => '202']));
        RoomStatus::query()->where('room_id', $room->id)->update(['status' => HousekeepingStatus::Clean]);
        app(SetRatePrices::class)->handle($this->tenant->owner, RatePlan::query()->findOrFail($this->reservation->rate_plan_id), [
            'room_type_id' => $room->room_type_id, 'from' => '2026-12-10', 'to' => '2026-12-11',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7], 'base_price' => 15050, 'extra_adult' => 2500, 'extra_child' => 1000,
        ]);

        app(MoveRoom::class)->handle($this->tenant->owner, $this->reservation, $room->id, reprice: true);

        $message = OutboxMessage::query()->where('integration', 'vfms')->where('event', 'changeRoom')->sole();
        expect($message->status)->toBe('delivered')
            ->and($message->payload['body']['accommodationPrice'])->toEqual(160.5);
    });
    Http::assertNothingSent();
});

// protects=VFMS receives the next date's tax-inclusive price without the infrastructure levy; fails_when=tax uses the closing date or the levy is added; no_claim=VFMS's own tax assessment.
test('nightly price changes include next-date tax but leave the levy to VFMS', function () {
    inTenant($this->tenant->property, function () {
        TaxRule::query()->create([
            'name' => 'VAT', 'zra_tax_category' => 'vat', 'kind' => 'percentage', 'rate' => 1500,
            'inclusive' => false, 'applies_to' => 'room', 'rounding' => 'half_up', 'effective_from' => '2026-12-11',
        ]);
        TaxRule::query()->create([
            'name' => 'Infrastructure levy', 'zra_tax_category' => 'hotel_levy', 'kind' => 'flat_per_person_night',
            'amount' => 300, 'currency' => 'USD', 'inclusive' => false, 'applies_to' => 'room',
            'rounding' => 'half_up', 'effective_from' => '2026-12-11',
        ]);
        $this->reservation->priceLines()->whereDate('date', '2026-12-11')->update(['amount' => 12550]);

        app(CloseBusinessDate::class)->handle($this->tenant->owner, $this->tenant->property, '2026-12-10');

        $message = OutboxMessage::query()->where('integration', 'vfms')->where('event', 'changeRoomPrice')->sole();
        expect($message->status)->toBe('delivered')
            ->and($message->payload['body']['rooms'][0]['roomPrice'])->toEqual(144.33);
    });
    Http::assertNothingSent();
});
