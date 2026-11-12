<?php

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
});

/** One room, Anna Berg (P1234567) as lead guest; checked in unless $checkIn is false. */
function generalReceiptStay(TenantFixture $tenant, array $details = [], bool $checkIn = true): Reservation
{
    return inTenant($tenant->property, function () use ($tenant, $details, $checkIn) {
        $form = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$form, 'adults' => 1, 'children' => 0, 'nightly_rate' => 11000,
            'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast', ...$details,
        ]);
        $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => 'Anna Berg', 'documents' => [['type' => 'passport', 'number' => 'P1234567', 'nationality' => 'DE']]]);
        ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);

        return $checkIn ? app(CheckIn::class)->handle($tenant->owner, $reservation) : $reservation;
    });
}

/** Posts an extra through the real charge route as the owner. */
function postGeneralReceiptExtra($test, TenantFixture $tenant, Folio $folio, array $charge): Posting
{
    $test->actingAs($tenant->owner)->post(route('folios.charges.store', $folio), [
        '_property' => $tenant->property->id, 'category' => 'restaurant', 'description' => 'LUNCH', 'quantity' => 1, 'unit_price' => 1000,
        'idempotency_key' => (string) Str::ulid(), ...$charge,
    ])->assertSessionHasNoErrors();

    return inTenant($tenant->property, fn () => Posting::query()->where('folio_id', $folio->id)->where('type', 'charge')->where('category', '!=', 'room')->latest('posted_at')->orderByDesc('id')->firstOrFail());
}

/** @return list<OutboxMessage> */
function generalReceiptMessages(TenantFixture $tenant, string $event): array
{
    return inTenant($tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->where('event', $event)->orderBy('created_at')->orderBy('sequence')->get()->all());
}

// protects=a checked-in guest's own extra stays a guest receipt (CS) with its discount (spec #249); fails_when=the type, document or discount changes; no_claim=Live acceptance.
test('a checked-in guest paying for themselves gets a guest receipt with the line discount', function () {
    $t = twoTenants()->a;
    $reservation = generalReceiptStay($t);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, ['quantity' => 2, 'unit_price' => 5000, 'discount' => 1000]);

    [$message] = generalReceiptMessages($t, 'services');
    expect($message->payload['body'])->toEqual([
        'documentNumber' => 'P1234567',
        'service' => [['itemName' => 'LUNCH', 'price' => 50, 'quantity' => 2, 'discount' => 10]],
        'referenceNumber' => $message->payload['body']['referenceNumber'],
        'type' => 'CS', 'currency' => 'USD',
    ])->and($posting->discount)->toBe(1000)
        ->and($posting->amount)->toBe(9000)
        ->and($posting->vfms_receipt_number)->toBe('TEST-RECEIPT-169');
});

// protects=an extra posted after check-out gets a general receipt in the guest's name, never their document (spec #249); fails_when=it is skipped, sent as CS or carries the passport; no_claim=Live acceptance.
test('a checked-out guest\'s extra gets a general receipt in the guest\'s full name', function () {
    $t = twoTenants()->a;
    $reservation = generalReceiptStay($t);
    inTenant($t->property, fn () => $reservation->update(['status' => ReservationStatus::CheckedOut]));
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, ['description' => 'LATE BAR', 'quantity' => 1, 'unit_price' => 1500]);

    [$message] = generalReceiptMessages($t, 'generalReceipt');
    expect($message->status)->toBe('delivered')
        ->and($message->payload['request_type'])->toBe('ACCOMMODATION_ADD_SERVICE')
        ->and($message->payload['path'])->toBe('service/')
        ->and($message->subject_id)->toBe($reservation->id)
        ->and($message->payload['body'])->toEqual([
            'documentNumber' => 'Anna Berg',
            'service' => [['itemName' => 'LATE BAR', 'price' => 15, 'quantity' => 1, 'discount' => 0]],
            'referenceNumber' => $message->payload['body']['referenceNumber'],
            'type' => 'WS', 'currency' => 'USD',
        ])
        ->and($message->payload['body']['documentNumber'])->not->toBe('P1234567')
        ->and($posting->fresh()->vfms_receipt_number)->toBe('TEST-RECEIPT-169')
        ->and(generalReceiptMessages($t, 'services'))->toBe([]);
});

// protects=a paying-institution stay's extras wait for one institution receipt (spec #249); fails_when=an institution extra files CS or WS at posting; no_claim=institution receipt issue.
test('a paying-institution stay holds its extras unreceipted', function () {
    $t = twoTenants()->a;
    generalReceiptStay($t, ['paying_institution_type' => 'government', 'paying_institution_name' => 'Ministry of Health', 'paying_institution_zrb_number' => 'WA2400117']);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    $posting = postGeneralReceiptExtra($this, $t, $folio, []);

    expect(generalReceiptMessages($t, 'services'))->toBe([])
        ->and(generalReceiptMessages($t, 'generalReceipt'))->toBe([])
        ->and($posting->fresh()->vfms_receipt_number)->toBeNull()
        ->and($posting->fresh()->general_receipt_id)->toBeNull();
});

// protects=a confirmed stay's extra is not filed (spec #249 out of scope); fails_when=a pre-arrival extra files anything; no_claim=fiscal exception listing.
test('a confirmed stay\'s extra is not filed', function () {
    $t = twoTenants()->a;
    generalReceiptStay($t, checkIn: false);
    $folio = inTenant($t->property, fn () => Folio::query()->sole());
    postGeneralReceiptExtra($this, $t, $folio, []);

    expect(inTenant($t->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->count()))->toBe(0);
});
