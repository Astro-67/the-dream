<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
});

// protects=an in-house extra files guide §2.11 services and keeps its fiscal receipt (#163); fails_when=the payload differs or the receipt is not saved on the charge; no_claim=production VFMS acceptance.
test('an in-house extra files one Sandbox services message with its receipt', function () {
    $tenant = twoTenants()->a;
    [$reservation, $posting] = inTenant($tenant->property, function () use ($tenant) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$details,
            'room_id' => Room::query()->sole()->id,
            'meal_plan' => 'bed-and-breakfast',
        ]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, [
            'name' => 'Anna Berg',
            'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']],
        ]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);

        $posting = app(PostExtraCharge::class)->handle($tenant->owner, Folio::query()->sole(), [
            'category' => 'bar', 'description' => 'WATER', 'quantity' => 2, 'unit_price' => 1000, 'idempotency_key' => 'water-1',
        ]);

        $event = $reservation->lifecycleEvents()->where('type', 'extra_posted')->sole();
        $message = OutboxMessage::query()->where('integration', 'vfms')->where('event', 'services')->sole();
        expect($message->status)->toBe('delivered')
            ->and($message->payload['path'])->toBe('service/')
            ->and($message->payload['request_type'])->toBe('ACCOMMODATION_ADD_SERVICE')
            ->and($message->payload['body'])->toEqual([
                'documentNumber' => 'P1234567',
                'service' => [['itemName' => 'WATER', 'price' => 10, 'quantity' => 2, 'discount' => 0]],
                'referenceNumber' => $reservation->reservation_number.'-'.$event->sequence,
                'type' => 'CS',
                'currency' => 'USD',
            ])
            ->and(app(VfmsPayloads::class)->services($event, $reservation, $tenant->property)['service'][0]['price'])->toBeFloat()
            ->and($posting->fresh()->vfms_receipt_number)->toStartWith('SBX-')
            ->and($posting->fresh()->vfms_receipt_number)->toBe($message->getAttribute('provider_references')['receiptNumber']);

        return [$reservation, $posting->fresh()];
    });
    $this->actingAs($tenant->owner)
        ->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->get(route('reservations.show', $reservation))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('folios.data.0.postings.data.0.vfms_receipt_number', $posting->vfms_receipt_number));
    Http::assertNothingSent();
});
