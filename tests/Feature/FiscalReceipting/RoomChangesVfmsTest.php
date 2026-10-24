<?php

use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\MoveRoom;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Models\Room;
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

// protects=guide §2.2 room moves follow check-in on the stay stream; fails_when=the destination, lead document, price or order is wrong; no_claim=production VFMS acceptance.
test('a Sandbox room move files the new room and lead document after check-in', function () {
    inTenant($this->tenant->property, function () {
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
                'currency' => 'USD',
                'accommodationPrice' => 110,
                'accommodationDiscount' => 0,
            ]);
    });
    Http::assertNothingSent();
});
