<?php

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Actions\FileAdvancePayment;
use App\Domains\FiscalReceipting\Actions\RecoverVfmsFiling;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FiscalReceipting\VfmsAdvance;
use App\Domains\FiscalReceipting\VfmsFilings;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\FrontDesk\Actions\CheckOut;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Rates\Models\RatePlan;
use App\Domains\Reservations\Actions\AmendReservation;
use App\Domains\Reservations\Actions\AssignRoom;
use App\Domains\Reservations\Actions\CancelReservation;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\OutboxMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    VfmsHttp::fake();
    config(['services.vfms.test_viewer_url' => 'https://vfms-test.example']);
});

/** @return array{Reservation, Receipt} */
function advanceVfmsPayment(TenantFixture $tenant, array $overrides = [], string $currency = 'USD'): array
{
    return inTenant($tenant->property, function () use ($tenant, $overrides, $currency) {
        $details = BookingForm::details($tenant->owner);
        RatePlan::query()->findOrFail($details['rate_plan_id'])->update(['currency' => $currency]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [
            ...$details, 'adults' => 1, 'children' => 0, 'room_id' => Room::query()->sole()->id,
            'booked_through' => 'tour-operator', 'tour_operator_name' => 'Island Tours',
            'tour_operator_registration' => 'TIN-ADVANCE', 'tour_operator_pays_for' => 'room-and-levy',
            'lead_guest' => ['first_name' => 'Advance', 'last_name' => 'Guest', 'nationality' => 'DE', 'document_type' => 'passport', 'document_number' => 'ADV-P1'],
            ...$overrides,
        ]);
        $payment = app(RecordPayment::class)->handle($tenant->owner, Folio::query()->sole(), [
            'method' => 'bank_transfer', 'amount' => 5000, 'currency' => $currency, 'idempotency_key' => 'advance-payment',
        ]);

        return [$reservation, $payment->receipt];
    });
}

function advanceVfmsDetails(): array
{
    return ['email' => 'accounts@island.example', 'phone' => '+255700000001', 'is_zra_registered' => false];
}

function advanceVfmsLinkDetails(array $overrides = []): array
{
    return [
        'link_existing' => true, 'booking_number' => 'EXTERNAL-BOOKING', 'receipt_number' => 'EXTERNAL-RECEIPT',
        'reason' => 'Advance already issued outside Karafuu; link its references to this payment',
        ...$overrides,
    ];
}

test('an existing deposit gets an accepted advance receipt without rewriting its payment receipt', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    $original = $receipt->attributesToArray();
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $message = VfmsAdvance::latest($reservation);
        expect($message->status)->toBe('delivered')
            ->and($message->payload['request_type'])->toBe('RESERVATION_BOOKING')
            ->and($message->payload['path'])->toBe('reservation/booking/')
            ->and($message->payload['body'])->toEqual([
                'bookingCompany' => 'Island Tours', 'email' => 'accounts@island.example', 'phoneNumber' => '+255700000001',
                'amountToBePaid' => 50.0, 'noOfGuest' => 1, 'currency' => 'USD',
                'startDate' => '2026-12-10T11:00:00.000Z', 'endDate' => '2026-12-12T08:00:00.000Z',
                'rooms' => [['roomId' => 1, 'roomPrice' => 100.0, 'noOfGuestPerRoom' => 1]],
                'customerType' => 2, 'salesCustomer' => 'Island Tours', 'regNumber' => 'TIN-ADVANCE',
                'referenceNumber' => $reservation->reservation_number.'-2',
                'isIncludeInfrastructure' => true, 'isIncludeAccommodation' => true, 'isIncludeOtherService' => false, 'isZraRegistered' => false,
            ])
            ->and($message->getAttribute('provider_references')['bookingNumber'])->toBe('TEST-BOOKING-1')
            ->and($message->getAttribute('provider_references')['receiptNumber'])->toBe('TEST-ADVANCE-1')
            ->and($message->getAttribute('provider_references')['referenceNumber'])->toBe('TEST-REFERENCE-1')
            ->and(OutboxMessage::query()->where('event', 'advanceBooking')->count())->toBe(1);
        $row = app(VfmsFilings::class)->rows($tenant->property, '2026-12-10', '2026-12-10')[0];
        expect($row['amount'])->toBe(5000)->and($row['call'])->toBe('Advance payment');
    });
    $this->withSession(['tenancy.organization_id' => $tenant->organization->id, 'tenancy.property_id' => $tenant->property->id])
        ->actingAs($tenant->owner)->get(route('receipts.show', $receipt))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('receipt.label', 'Non-fiscal receipt')
            ->where('advancePayment.bookingNumber', 'TEST-BOOKING-1')
            ->where('advancePayment.receiptUrl', 'https://vfms-test.example/receipt-form/TEST-ADVANCE-1')
            ->where('advancePayment.mode', 'sandbox'));
    expect(inTenant($tenant->property, fn () => $receipt->fresh()->attributesToArray()))->toEqual($original);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === VfmsHttp::TEST_URL.'reservation/booking/'
        && $request['rooms'][0]['roomId'] !== 1 && is_int($request['rooms'][0]['roomId'])
        && $request['isZraRegistered'] === false);
});

test('advance check-in links the accepted booking and receipt and never sends ordinary accommodation', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $stay = app(CheckIn::class)->handle($tenant->owner, $reservation);
        $message = OutboxMessage::query()->where('event', 'advanceAccommodation')->sole();
        expect($stay->status->value)->toBe('checked_in')
            ->and($message->status)->toBe('delivered')
            ->and($message->payload['request_type'])->toBe('ACCOMMODATION_BOOKING_REQUEST')
            ->and($message->payload['path'])->toBe('accommodation/booking/')
            ->and($message->payload['body']['bookingNumber'])->toBe('TEST-BOOKING-1')
            ->and($message->payload['body']['receiptno'])->toBe('TEST-ADVANCE-1')
            ->and($message->payload['body']['salesCurrency'])->toBe('USD')
            ->and($message->payload['body']['accomodationPrice'])->toEqual(100.0)
            ->and($message->payload['body']['guest'][0]['documentNo'])->toBe('ADV-P1')
            ->and(OutboxMessage::query()->where('event', 'accommodation')->count())->toBe(0);
    });
    Http::assertNotSent(fn (Request $request) => $request->url() === VfmsHttp::TEST_URL.'accommodation/');
});

test('incomplete or ambiguous advance responses never invent a receipt or permit check-in', function (array $body, int $status, string $outcome) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([VfmsHttp::TEST_URL.'reservation/booking/' => Http::response($body, $status)]);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt, $outcome) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $message = VfmsAdvance::latest($reservation);
        expect($message->status)->toBe($outcome)->and($message->getAttribute('provider_references')['receiptNumber'] ?? null)->toBeNull();
        expect(fn () => app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails()))->toThrow(ValidationException::class);
        expect(VfmsAdvance::linked($reservation))->toBeNull();
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        expect(fn () => app(CheckIn::class)->handle($tenant->owner, $reservation))->toThrow(ValidationException::class);
        expect($reservation->fresh()->status->value)->toBe('confirmed')
            ->and(OutboxMessage::query()->where('event', 'advanceAccommodation')->count())->toBe(0);
    });
})->with([
    [[], 200, 'unknown'],
    [['bookingNumber' => 'B-1'], 200, 'unknown'],
    [['receiptNo' => 'R-1'], 200, 'unknown'],
    [['bookingNumber' => 'B-1', 'receiptNo' => 'R-1', 'receiptNumber' => 'R-2'], 200, 'unknown'],
    [['error' => 'duplicate reference'], 400, 'unknown'],
    [['error' => 'invalid company'], 400, 'rejected'],
]);

test('advance filing refuses unsupported deposits without changing the payment', function (array $overrides) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, $overrides);
    inTenant($tenant->property, function () use ($tenant, $receipt) {
        expect(fn () => app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails()))->toThrow(ValidationException::class);
        expect(OutboxMessage::query()->where('event', 'advanceBooking')->count())->toBe(0)
            ->and($receipt->posting()->firstOrFail()->amount)->toBe(-5000);
    });
    Http::assertNothingSent();
})->with([[['room_id' => null]], [['booked_through' => 'guest']]]);

test('advance filing has payment permissions property intent validation and rejects invalid contact input', function () {
    $t = twoTenants();
    [, $receipt] = advanceVfmsPayment($t->a);
    $payload = [...advanceVfmsDetails(), '_property' => $t->a->property->id];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->member(OrganizationRole::Auditor))
        ->postJson(route('receipts.vfms-advance.store', $receipt), $payload)->assertForbidden();
    $this->actingAs($t->a->owner)->postJson(route('receipts.vfms-advance.store', $receipt), [...$payload, 'email' => 'bad'])
        ->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson(route('receipts.vfms-advance.store', $receipt), [...$payload, '_property' => $t->a->otherProperty->id])->assertNotFound();
    $this->post(route('receipts.vfms-advance.store', $receipt), [...$payload, 'is_zra_registered' => '0'])->assertSessionHasNoErrors()->assertRedirect();
    inTenant($t->a->property, fn () => expect(VfmsAdvance::latest(Reservation::query()->sole())->payload['body']['isZraRegistered'])->toBeFalse());
});

test('advance receipts cannot silently be refunded reversed cancelled amended or unassigned', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $payment = $receipt->posting()->firstOrFail();
        expect(fn () => app(ReversePosting::class)->handle($tenant->owner, $payment, 'Correction'))->toThrow(ValidationException::class);
        expect(fn () => app(RecordAdjustment::class)->handle($tenant->owner, Folio::query()->sole(), 'refund', [
            'amount' => 1000, 'method' => 'bank_transfer', 'reason' => 'Refund', 'idempotency_key' => 'advance-refund',
        ]))->toThrow(ValidationException::class);
        expect(fn () => app(CancelReservation::class)->handle($tenant->owner, $reservation, 'Cancel'))->toThrow(ValidationException::class);
        expect(fn () => app(AmendReservation::class)->handle($tenant->owner, $reservation, []))->toThrow(ValidationException::class);
        expect(fn () => app(AssignRoom::class)->handle($tenant->owner, $reservation, null))->toThrow(ValidationException::class);
        expect($reservation->fresh()->status->value)->toBe('confirmed')->and($payment->fresh()->amount)->toBe(-5000);
    });
});

test('an advance from Sandbox cannot be linked to a Live check-in', function (bool $external) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt, $external) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, $external ? advanceVfmsLinkDetails() : advanceVfmsDetails());
        VfmsCredential::for($tenant->property)->fill(['mode' => VfmsMode::Live])->save();
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        expect(fn () => app(CheckIn::class)->handle($tenant->owner, $reservation))->toThrow(ValidationException::class);
        expect($reservation->fresh()->status->value)->toBe('confirmed');
    });
})->with([false, true]);

test('Live advances use unprefixed room IDs actual payment amounts and the existing currency mapping', function (string $currency, string $wireCurrency, float $amount) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, [], $currency);
    config(['services.vfms.live_url' => 'https://vfms-live.example/api/']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://vfms-live.example/api/reservation/booking/' => Http::response([
        'bookingNumber' => 'LIVE-BOOKING', 'receiptNumber' => 'LIVE-RECEIPT',
    ])]);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation) {
        VfmsCredential::for($tenant->property)->fill([
            'mode' => VfmsMode::Live, 'integration_id' => 'LIVE-INT', 'registration_id' => 'LIVE-REG', 'token' => 'LIVE-TOKEN',
        ])->save();
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $message = VfmsAdvance::latest($reservation);
        expect($message->status)->toBe('delivered')
            ->and($message->getAttribute('provider_references')['mode'])->toBe('live')
            ->and($message->getAttribute('provider_references')['receiptUrl'])->toBe('https://portalvfms.zanrevenue.org/receipt-form/LIVE-RECEIPT');
    });
    Http::assertSent(fn (Request $request) => $request['rooms'][0]['roomId'] === 1
        && $request['amountToBePaid'] == $amount && $request['currency'] === $wireCurrency);
})->with([['USD', 'USD', 50.0], ['EUR', 'EURO', 50.0], ['TZS', 'TZS', 5000.0]]);

test('payments not included in the fiscal advance remain correctable', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $other = app(RecordPayment::class)->handle($tenant->owner, Folio::query()->sole(), [
            'method' => 'bank_transfer', 'amount' => 1000, 'currency' => 'USD', 'idempotency_key' => 'other-payment',
        ]);
        $reversal = app(ReversePosting::class)->handle($tenant->owner, $other, 'Correct the other payment');
        expect($reversal->amount)->toBe(1000)->and(VfmsAdvance::latest($reservation)->status)->toBe('delivered');
    });
});

test('an advance stay keeps normal services and check-out without counting the deposit twice', function (bool $external) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, $external ? ['booked_through' => 'guest'] : []);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation, $external) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, $external ? advanceVfmsLinkDetails() : advanceVfmsDetails());
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        $report = json_decode(file_get_contents(base_path('tests/Fixtures/vfms/checkout.json')), true, flags: JSON_THROW_ON_ERROR)[0];
        $report['guest']['documentNo'] = 'ADV-P1';
        $report['room']['room'][0]['amount'] = 200;
        $report['room']['room'][0]['accommodationPrice'] = 100;
        $report['service']['subTotal']['totalAmount'] = 5;
        $report['total']['totalAmount'] = 205;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            VfmsHttp::TEST_URL.'service/' => Http::response(['receiptNumber' => 'ADV-SERVICE']),
            VfmsHttp::TEST_URL.'guest/checkout/' => Http::response([$report]),
        ]);
        $folio = Folio::query()->sole();
        app(PostExtraCharge::class)->handle($tenant->owner, $folio, ['category' => 'bar', 'description' => 'WATER', 'quantity' => 1, 'unit_price' => 500]);
        $tenant->property->update(['business_date' => '2026-12-12']);
        app(CheckOut::class)->handle($tenant->owner, $reservation, 'Leave balance open for the operator');
        expect($reservation->fresh()->status->value)->toBe('checked_out')
            ->and(OutboxMessage::query()->where('event', 'services')->sole()->status)->toBe('delivered')
            ->and(OutboxMessage::query()->where('event', 'checkout')->sole()->status)->toBe('delivered')
            ->and($receipt->posting()->firstOrFail()->amount)->toBe(-5000)
            ->and($folio->balance()->amount)->toBe(15500);
    });
})->with([false, true]);

test('every reservation source can link existing ZRA references and use the shared advance check-in', function (string $source, string $bookedThrough) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, ['source' => $source, 'booked_through' => $bookedThrough]);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails());
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails());
        expect($reservation->lifecycleEvents()->where('type', 'advance_booking_linked')->count())->toBe(1)
            ->and(VfmsAdvance::latest($reservation))->toBeNull()
            ->and(VfmsAdvance::forReceipt($receipt, true)['status'])->toBe('linked')
            ->and(VfmsAdvance::forReceipt($receipt, true)['source'])->toBe('external');
        Http::assertNothingSent();
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        $message = VfmsAdvance::checkInFiling($reservation);
        expect($message->status)->toBe('delivered')
            ->and($message->payload['request_type'])->toBe('ACCOMMODATION_BOOKING_REQUEST')
            ->and($message->payload['body'])->toEqual([
                'referenceNumber' => $reservation->reservation_number.'-3',
                'roomId' => 1, 'roomName' => '101',
                'accomodationPrice' => 100.0, 'accomodationDiscount' => 0.0, 'salesCurrency' => 'USD',
                'checkInDate' => '2026-12-10T06:00:00.000Z', 'checkOutDate' => '2026-12-12T08:00:00.000Z',
                'bookingNumber' => 'EXTERNAL-BOOKING', 'receiptno' => 'EXTERNAL-RECEIPT',
                'guest' => [['documentName' => 'PASSPORT', 'documentNo' => 'ADV-P1', 'country' => 'GERMANY', 'fullName' => 'Advance Guest']],
            ])
            ->and(OutboxMessage::query()->where('event', 'accommodation')->count())->toBe(0);
    });
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request) => $request->url() === VfmsHttp::TEST_URL.'reservation/booking/');
})->with([['direct', 'guest'], ['phone', 'guest'], ['walk-in', 'guest'], ['outside-integration', 'guest'], ['whatsapp', 'guest'], ['email', 'guest'], ['website', 'guest'], ['direct', 'tour-operator']]);

test('linking an existing advance requires references reason permissions and never fabricates receipt acceptance', function () {
    $t = twoTenants();
    [$reservation, $receipt] = advanceVfmsPayment($t->a, ['booked_through' => 'guest']);
    $payload = [...advanceVfmsLinkDetails(), '_property' => $t->a->property->id];
    $this->withSession(['tenancy.organization_id' => $t->a->organization->id, 'tenancy.property_id' => $t->a->property->id])
        ->actingAs($t->a->member(OrganizationRole::Auditor))
        ->postJson(route('receipts.vfms-advance.store', $receipt), $payload)->assertForbidden();
    $this->actingAs($t->a->owner)->postJson(route('receipts.vfms-advance.store', $receipt), [...$payload, 'receipt_number' => '', 'reason' => ''])
        ->assertUnprocessable()->assertJsonValidationErrors(['receipt_number', 'reason']);
    $this->post(route('receipts.vfms-advance.store', $receipt), $payload)->assertSessionHasNoErrors()->assertRedirect();
    $this->get(route('receipts.show', $receipt))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('receipt.label', 'Non-fiscal receipt')
        ->where('advancePayment.source', 'external')
        ->where('advancePayment.status', 'linked')
        ->where('advancePayment.fileUrl', null)
        ->where('advancePayment.bookingNumber', 'EXTERNAL-BOOKING'));
    inTenant($t->a->property, function () use ($reservation, $receipt) {
        expect(VfmsAdvance::linked($reservation)->snapshot['posting_id'])->toBe($receipt->posting_id)
            ->and(OutboxMessage::query()->where('event', 'advanceBooking')->count())->toBe(0);
    });
    Http::assertNothingSent();
});

test('a linked existing advance prevents a second receipt and protects its payment', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails());
        expect(fn () => app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails()))->toThrow(ValidationException::class);
        expect(fn () => app(ReversePosting::class)->handle($tenant->owner, $receipt->posting()->firstOrFail(), 'Reverse'))->toThrow(ValidationException::class);
        expect(VfmsAdvance::paymentId($reservation))->toBe($receipt->posting_id);
    });
    Http::assertNothingSent();
});

test('a channel-collected deposit can link an existing advance without claiming a second collection', function () {
    $tenant = twoTenants()->a;
    [$reservation] = advanceVfmsPayment($tenant, [
        'booked_through' => 'guest', 'payment_collector' => 'channel', 'channel_collected_amount' => 7000,
    ]);
    inTenant($tenant->property, function () use ($tenant, $reservation) {
        $payment = app(RecordPayment::class)->handle($tenant->owner, Folio::query()->sole(), [
            'method' => 'channel_collected', 'currency' => 'USD', 'idempotency_key' => 'channel-advance',
        ]);
        app(FileAdvancePayment::class)->handle($tenant->owner, $payment->receipt, advanceVfmsLinkDetails());
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        expect(VfmsAdvance::checkInFiling($reservation)->status)->toBe('delivered')
            ->and($payment->fresh()->amount)->toBe(-7000)
            ->and(VfmsAdvance::paymentId($reservation))->toBe($payment->id);
    });
    Http::assertSentCount(1);
});

test('a confirmed rejection allows audited correction of externally supplied advance references', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, ['booked_through' => 'guest']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([VfmsHttp::TEST_URL.'accommodation/booking/' => Http::sequence()
        ->push(['error' => 'Invalid receipt'], 400)->push(['responseNumber' => 'EXTERNAL-CHECK-IN'])]);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails());
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        $message = VfmsAdvance::checkInFiling($reservation);
        expect($message->status)->toBe('rejected');
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails(['receipt_number' => 'CORRECT-RECEIPT', 'reason' => 'Corrected a transcription error after ZRA rejected the first check-in']));
        expect($reservation->lifecycleEvents()->where('type', 'checked_in')->sole()->snapshot['advance_booking']['receiptNumber'])->toBe('EXTERNAL-RECEIPT');
        app(RecoverVfmsFiling::class)->handle($tenant->owner, $reservation, $message, 'Retry with the corrected references', refile: true);
        app(DeliveryEngine::class)->deliver($message->id);
        expect($message->fresh()->status)->toBe('delivered')
            ->and($message->fresh()->payload['body']['receiptno'])->toBe('CORRECT-RECEIPT')
            ->and($receipt->posting()->firstOrFail()->amount)->toBe(-5000);
    });
    Http::assertSentCount(2);
});

test('accepted or uncertain advance check-ins cannot have their external references replaced', function (array $response, string $status) {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant, ['booked_through' => 'guest']);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([VfmsHttp::TEST_URL.'accommodation/booking/' => Http::response($response)]);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt, $status) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails());
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        app(CheckIn::class)->handle($tenant->owner, $reservation);
        expect(VfmsAdvance::checkInFiling($reservation)->status)->toBe($status);
        expect(fn () => app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsLinkDetails(['receipt_number' => 'DIFFERENT'])))->toThrow(ValidationException::class);
        expect($reservation->lifecycleEvents()->where('type', 'advance_booking_linked')->count())->toBe(1);
    });
    Http::assertSentCount(1);
})->with([[[], 'unknown'], [['responseNumber' => 'ACCEPTED'], 'delivered']]);

test('confirmed advance rejections can be recovered more than once without changing money', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([VfmsHttp::TEST_URL.'reservation/booking/' => Http::sequence()
        ->push(['error' => 'Refused'], 400)->push(['error' => 'Still refused'], 400)
        ->push(['bookingNumber' => 'RECOVERED-BOOKING', 'receiptNo' => 'RECOVERED-RECEIPT'])]);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        $message = VfmsAdvance::latest($reservation);
        expect($message->status)->toBe('rejected');
        foreach ([1, 2] as $revision) {
            app(RecoverVfmsFiling::class)->handle($tenant->owner, $reservation, $message, 'Corrected provider configuration', refile: true);
            app(DeliveryEngine::class)->deliver($message->id);
            $message = $message->fresh();
            expect($message->payload['body']['referenceNumber'])->toBe($reservation->reservation_number.'-2R'.$revision)
                ->and($message->payload['body']['amountToBePaid'])->toEqual(50.0);
        }
        expect($message->status)->toBe('delivered')
            ->and($message->getAttribute('provider_references')['bookingNumber'])->toBe('RECOVERED-BOOKING')
            ->and($receipt->posting()->firstOrFail()->amount)->toBe(-5000);
    });
    Http::assertSentCount(3);
});
