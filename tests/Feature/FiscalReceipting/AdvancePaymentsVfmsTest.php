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

test('an advance from Sandbox cannot be linked to a Live check-in', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $reservation, $receipt) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
        VfmsCredential::for($tenant->property)->fill(['mode' => VfmsMode::Live])->save();
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        expect(fn () => app(CheckIn::class)->handle($tenant->owner, $reservation))->toThrow(ValidationException::class);
        expect($reservation->fresh()->status->value)->toBe('confirmed');
    });
});

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

test('an advance stay keeps normal services and check-out without counting the deposit twice', function () {
    $tenant = twoTenants()->a;
    [$reservation, $receipt] = advanceVfmsPayment($tenant);
    inTenant($tenant->property, function () use ($tenant, $receipt, $reservation) {
        app(FileAdvancePayment::class)->handle($tenant->owner, $receipt, advanceVfmsDetails());
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
});

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
