<?php

use App\Domains\FiscalReceipting\Adapters\SandboxVfms;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Domains\FrontDesk\Actions\CheckIn;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Inventory\Models\Room;
use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Reservations\Actions\CreateReservation;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\PropertyStatus;
use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\RestoreGate;
use App\Support\Outbox\RestoreReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BookingForm;
use Tests\Support\TenantFixture;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-10 09:00:00', 'Africa/Dar_es_Salaam'));
    Http::preventStrayRequests();
});

/**
 * Checks in a stay of two guests in room 101 for 10-12 December at 100.00 USD a night.
 *
 * @param  array<string, mixed>  $booking  reservation fields to override
 */
function checkInTwoGuests(TenantFixture $tenant, array $booking = [], ?Property $property = null, string $currency = 'USD'): Reservation
{
    return inTenant($property ?? $tenant->property, function () use ($tenant, $booking, $currency) {
        $details = BookingForm::details($tenant->owner);
        RoomStatus::query()->update(['status' => HousekeepingStatus::Clean]);
        $reservation = app(CreateReservation::class)->handle($tenant->owner, [...$details, 'room_id' => Room::query()->sole()->id, 'meal_plan' => 'bed-and-breakfast', ...$booking]);
        $reservation->priceLines()->update(['currency' => $currency]);
        foreach ([['Anna Berg', 'P1234567', 'DE'], ['Ola Berg', 'P7654321', 'NO']] as [$name, $number, $country]) {
            $guest = app(CreateGuest::class)->handle($tenant->owner, ['name' => $name, 'documents' => [['type' => 'passport', 'number' => $number, 'nationality' => $country]]]);
            ReservationGuest::query()->create(['reservation_id' => $reservation->id, 'guest_id' => $guest->id]);
        }

        return app(CheckIn::class)->handle($tenant->owner, $reservation);
    });
}

function liveCredentials(TenantFixture $tenant, string $token = 'tok-live'): void
{
    inTenant($tenant->property, fn () => VfmsCredential::query()->create(['integration_id' => 'INT-1001', 'registration_id' => 'REG-2002', 'token' => $token, 'mode' => VfmsMode::Live, 'live_since' => now()]));
}

/** @return list<OutboxMessage> */
function vfmsMessages(TenantFixture $tenant, ?Property $property = null): array
{
    return inTenant($property ?? $tenant->property, fn () => OutboxMessage::query()->where('integration', 'vfms')->orderBy('sequence')->get()->all());
}

// protects=a Sandbox check-in files the stay with VFMS in the guide's shape (spec #156, guide §2.1); fails_when=a field is missing, misnamed or of the wrong type, or the message is not accepted; no_claim=that VFMS production accepts these values.
test('a Sandbox check-in of two guests files one accepted accommodation message', function () {
    $t = twoTenants();
    $reservation = checkInTwoGuests($t->a);

    $messages = vfmsMessages($t->a);
    expect($messages)->toHaveCount(1);
    [$message] = $messages;
    $room = inTenant($t->a->property, fn () => Room::query()->sole());
    expect($message->status)->toBe('delivered')
        ->and($message->event)->toBe('accommodation')
        ->and($message->payload['mode'])->toBe('sandbox')
        ->and($message->payload['body']['roomId'])->toBeInt()
        ->and($message->payload['body']['roomName'])->toBe('101')
        ->and($message->payload['body']['accommodationPrice'])->toBeIn([110, 110.0])
        ->and($message->payload['body']['accommodationDiscount'])->toBeIn([0, 0.0])
        ->and($message->payload['body'])->toEqual([
            'paymentPackage' => 'bed-and-breakfast',
            'checkInDate' => '2026-12-10',
            'guest' => [
                ['documentName' => 'passport', 'documentNo' => 'P1234567', 'country' => 'Germany', 'fullName' => 'Anna Berg'],
                ['documentName' => 'passport', 'documentNo' => 'P7654321', 'country' => 'Norway', 'fullName' => 'Ola Berg'],
            ],
            'roomId' => $room->vfms_room_id,
            'roomName' => '101',
            'accommodationSource' => 'DIRECT',
            // 2 adults and 1 child in a room for 2: 100.00 plus one extra child. JSON keeps no float type, so 110.0 reads back as 110.
            'accommodationPrice' => 110,
            'accommodationDiscount' => 0,
            'referenceNumber' => $reservation->reservation_number.'-2',
            'currency' => 'USD',
        ])
        ->and(inTenant($t->a->property, fn () => $message->attempts()->sole()->provider_references))->toHaveKey('receiptNumber');
    expect(inTenant($t->a->property, fn () => $message->attempts()->sole()->provider_references['receiptNumber']))->toStartWith('SBX-');
});

// protects=Sandbox and incomplete credentials never contact VFMS (#159); fails_when=any HTTP request escapes either guard; no_claim=Live gateway availability.
test('Sandbox or missing credentials never sends an HTTP request', function (bool $sandbox, bool $complete) {
    $t = twoTenants();
    liveCredentials($t->a);
    inTenant($t->a->property, function () use ($t, $sandbox, $complete) {
        VfmsCredential::for($t->a->property)->forceFill([
            'mode' => $sandbox ? VfmsMode::Sandbox : VfmsMode::Live,
            'token' => $complete ? 'tok-live' : null,
        ])->save();
    });

    checkInTwoGuests($t->a);

    Http::assertNothingSent();
    expect(vfmsMessages($t->a)[0]->status)->toBe($sandbox ? 'delivered' : 'rejected');
})->with([
    'Sandbox with credentials' => [true, true],
    'Sandbox without credentials' => [true, false],
    'Live without credentials' => [false, false],
]);

// protects=Sandbox refuses bodies missing guide-required fields (#159); fails_when=a missing body, guest or operator field is accepted; no_claim=VFMS production validation.
test('Sandbox rejects missing required accommodation fields', function (string $field) {
    $t = twoTenants();
    checkInTwoGuests($t->a, ['booked_through' => 'tour-operator', 'tour_operator_name' => 'Safari Co', 'tour_operator_registration' => 'ZRA-123', 'tour_operator_pays_for' => 'room-only']);
    $payload = vfmsMessages($t->a)[0]->payload;
    data_forget($payload['body'], $field);

    $result = app(SandboxVfms::class)->send($payload, new VfmsCredential);

    expect($result->outcome)->toBe('rejected')
        ->and($result->responseBody['missing'])->toContain($field);
    Http::assertNothingSent();
})->with([
    'paymentPackage', 'checkInDate', 'guest', 'roomId', 'roomName',
    'accommodationSource', 'accommodationPrice', 'accommodationDiscount',
    'referenceNumber', 'currency', 'guest.0.documentName', 'guest.0.documentNo',
    'guest.0.country', 'guest.0.fullName', 'tourRegNo', 'costOptions',
]);

// protects=tour-operator stays name the operator and what it pays for (spec #156, ADR 0005); fails_when=a choice maps to the wrong cost options or the registration number is dropped; no_claim=how VFMS bills the operator.
test('a tour-operator stay is sent with its registration number and cost options', function (string $paysFor, string $costOptions) {
    $t = twoTenants();
    checkInTwoGuests($t->a, ['booked_through' => 'tour-operator', 'tour_operator_name' => 'Island Tours', 'tour_operator_registration' => 'ZRA-123', 'tour_operator_pays_for' => $paysFor]);

    expect(vfmsMessages($t->a)[0]->payload['body'])->toMatchArray(['accommodationSource' => 'TOUR OPERATOR', 'tourRegNo' => 'ZRA-123', 'costOptions' => $costOptions]);
})->with([
    'room only' => ['room-only', '5'],
    'room and levy' => ['room-and-levy', '5,6'],
    'room and other services' => ['room-and-other-services', '5,7'],
    'room, levy and other services' => ['room-levy-and-other-services', '5,6,7'],
]);

// protects=VFMS spells euro EURO (guide §2.1); fails_when=EUR is sent as EUR; no_claim=other currencies VFMS accepts.
test('a euro stay is sent in EURO', function () {
    $t = twoTenants();
    checkInTwoGuests($t->a, currency: 'EUR');

    expect(vfmsMessages($t->a)[0]->payload['body']['currency'])->toBe('EURO');
});

// protects=mainland stays never reach VFMS (ADR 0014); fails_when=a mainland check-in records a VFMS message; no_claim=TRA reporting.
test('a mainland check-in files nothing with VFMS', function () {
    $t = twoTenants();
    checkInTwoGuests($t->a, property: $t->a->otherProperty);

    expect(vfmsMessages($t->a, $t->a->otherProperty))->toBe([]);
});

// protects=stays recorded before the property went live are not filed (spec #156); fails_when=a test event records a VFMS message; no_claim=what happens to test data at go-live.
test('a test event files nothing with VFMS', function () {
    $t = twoTenants();
    $t->a->property->forceFill(['status' => PropertyStatus::Setup])->save();
    inTenant($t->a->property, function () use ($t) {
        $details = BookingForm::details($t->a->owner);
        $reservation = app(CreateReservation::class)->handle($t->a->owner, [...$details, 'room_id' => Room::query()->sole()->id]);
        DB::transaction(fn () => app(RecordLifecycleEvent::class)->handle($reservation, 'checked_in', $t->a->owner));
        expect($reservation->lifecycleEvents()->where('type', 'checked_in')->sole()->test)->toBeTrue();
    });

    expect(vfmsMessages($t->a))->toBe([]);
});

// protects=Live filing goes to the guide's URL with its four headers (guide §1.4, §2.1); fails_when=a header is missing, misspelt or carries the wrong request type; no_claim=the gateway's TLS or response body.
test('a Live check-in posts to the VFMS gateway with the four headers', function () {
    $t = twoTenants();
    liveCredentials($t->a);
    Http::fake(['gateway.zanrevenue.org/*' => Http::response(['status' => 'ok'], 200)]);

    checkInTwoGuests($t->a);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://gateway.zanrevenue.org/api/accommodation/'
        && $request->method() === 'POST'
        && $request->header('Content-type') === ['application/json']
        && $request->header('vfms-request-type') === ['ACCOMMODATION_REQUEST']
        && $request->header('vfms-intergration-id') === ['INT-1001']
        && $request->header('vfms-token-id') === ['tok-live']
        && $request['roomName'] === '101');
    expect(vfmsMessages($t->a)[0]->status)->toBe('delivered')->and(vfmsMessages($t->a)[0]->payload['mode'])->toBe('live');
});

// protects=each gateway answer maps to the outbox outcome the spec names; fails_when=a status lands in the wrong outcome, so a stay is marked filed or refused wrongly; no_claim=reconciliation of unknown outcomes (#165).
test('each VFMS gateway answer maps to its delivery outcome', function (Closure $answer, string $status, bool $refused) {
    $t = twoTenants();
    liveCredentials($t->a);
    Http::fake(['gateway.zanrevenue.org/*' => $answer]);

    checkInTwoGuests($t->a);

    expect(vfmsMessages($t->a)[0]->status)->toBe($status)
        ->and(VfmsCredential::for($t->a->property)->credentials_refused_at !== null)->toBe($refused);
})->with([
    '200' => [fn () => Http::response([], 200), 'delivered', false],
    '201' => [fn () => Http::response([], 201), 'delivered', false],
    '400' => [fn () => Http::response([], 400), 'rejected', false],
    '401' => [fn () => Http::response([], 401), 'rejected', true],
    '403' => [fn () => Http::response([], 403), 'rejected', false],
    '404' => [fn () => Http::response([], 404), 'rejected', false],
    '500' => [fn () => Http::response([], 500), 'unknown', false],
    '503' => [fn () => Http::response([], 503), 'unknown', false],
    'connection error' => [fn () => Http::failedConnection(), 'unknown', false],
    'timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out'), 'unknown', false],
]);

// protects=credentials never enter delivery records (spec #156 AC 2); fails_when=the token appears in an outbox payload or attempt body; no_claim=logs and audit (VfmsSettingsTest).
test('the VFMS token is absent from every outbox payload and attempt body', function () {
    $t = twoTenants();
    $marker = 'MARKER-TOKEN-9c2e';
    liveCredentials($t->a, $marker);
    Http::fake(['gateway.zanrevenue.org/*' => Http::response(['ok' => true], 200)]);

    checkInTwoGuests($t->a);

    $records = inTenant($t->a->property, fn () => json_encode([
        OutboxMessage::query()->get()->map->payload,
        OutboxAttempt::query()->get()->map(fn (OutboxAttempt $attempt) => [$attempt->request_body, $attempt->response_body, $attempt->provider_references]),
    ]));
    expect($records)->toContain('ACCOMMODATION')->not->toContain($marker)->not->toContain('INT-1001');
});

// protects=an uncertain check-in already filed by VFMS is not filed twice (#165); fails_when=delivery resends instead of retaining the report numbers; no_claim=the production report response shape, which guide 1.6 does not document.
test('VFMS reconciliation finds an uncertain check-in without resending', function (Closure $answer) {
    $t = twoTenants();
    liveCredentials($t->a);
    $reference = null;
    $checkIns = 0;
    Http::fake([
        'gateway.zanrevenue.org/api/accommodation/' => function (Request $request) use (&$reference, &$checkIns, $answer) {
            $checkIns++;
            $reference = $request['referenceNumber'];

            return $answer();
        },
        'gateway.zanrevenue.org/api/accommodationReport/' => function (Request $request) use (&$reference) {
            expect($request->header('vfms-request-type'))->toBe(['ACCOMMODATION_REPORT'])
                ->and($request->header('vfms-intergration-id'))->toBe(['INT-1001'])
                ->and($request->header('vfms-token-id'))->toBe(['tok-live'])
                ->and($request->method())->toBe('POST')
                ->and($request->data())->toEqual(['startDate' => '2026-12-10', 'endDate' => '2026-12-10', 'refno' => $reference]);

            return Http::response([['referenceNumber' => $reference, 'receiptNumber' => 'VFMS-42']], 200);
        },
    ]);

    checkInTwoGuests($t->a);

    $message = vfmsMessages($t->a)[0];
    expect($message->status)->toBe('delivered')
        ->and($message->provider_references)->toEqual(['mode' => 'live', 'receiptNumber' => 'VFMS-42']);
    expect($checkIns)->toBe(1);
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/accommodationReport/')))->toHaveCount(1);
})->with([
    'timeout' => [fn () => throw new ConnectionException('Operation timed out')],
    'connection error' => [fn () => Http::failedConnection()],
    '500' => [fn () => Http::response([], 500)],
    '503' => [fn () => Http::response([], 503)],
]);

// protects=confirmed absence permits exactly one unchanged resend (#165); fails_when=the reference or body changes, or a second uncertain answer loops; no_claim=VFMS production report completeness.
test('VFMS reconciliation resends an absent check-in once with the original reference', function (bool $resendTimesOut, array $rows) {
    $t = twoTenants();
    liveCredentials($t->a);
    $bodies = [];
    Http::fake([
        'gateway.zanrevenue.org/api/accommodation/' => function (Request $request) use (&$bodies, $resendTimesOut) {
            $bodies[] = $request->data();
            if (count($bodies) === 1 || $resendTimesOut) {
                throw new ConnectionException('Operation timed out');
            }

            return Http::response(['receiptNumber' => 'VFMS-43'], 201);
        },
        'gateway.zanrevenue.org/api/accommodationReport/' => Http::response($rows, 200),
    ]);

    checkInTwoGuests($t->a);

    $message = vfmsMessages($t->a)[0];
    expect($bodies)->toHaveCount(2)
        ->and($bodies[1])->toEqual($bodies[0])
        ->and($message->payload['body'])->toEqual($bodies[0])
        ->and($message->status)->toBe($resendTimesOut ? 'unknown' : 'delivered');
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/accommodationReport/')))->toHaveCount(1);
})->with([
    'empty report' => [false, []],
    'different reference' => [false, [['referenceNumber' => 'another-stay', 'receiptNumber' => 'VFMS-99']]],
    'resend also times out' => [true, []],
]);

// protects=failed or ambiguous reports hold unknown work for existing compliance recovery (#165); fails_when=a lookup failure permits a resend or automatic retry; no_claim=live report availability.
test('VFMS reconciliation leaves a failed lookup unknown for the compliance operator', function (Closure $reportAnswer) {
    $t = twoTenants();
    liveCredentials($t->a);
    $checkIns = 0;
    Http::fake([
        'gateway.zanrevenue.org/api/accommodation/' => function () use (&$checkIns) {
            $checkIns++;

            throw new ConnectionException('Operation timed out');
        },
        'gateway.zanrevenue.org/api/accommodationReport/' => $reportAnswer,
    ]);

    checkInTwoGuests($t->a);

    $message = vfmsMessages($t->a)[0];
    expect($message->status)->toBe('unknown');
    $this->travel(2)->minutes();
    inTenant($t->a->property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    expect($checkIns)->toBe(1)
        ->and(vfmsMessages($t->a)[0]->attempt_count)->toBe(1);
    $operator = platformStaff(PlatformRole::ComplianceOperator);
    $this->artisan('outbox:retry', [
        'message' => $message->id,
        '--actor' => $operator->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Check unknown VFMS delivery',
    ])->assertFailed();
    $this->artisan('outbox:confirm-accepted', [
        'message' => $message->id,
        '--actor' => $operator->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Verified filing in the VFMS portal',
        '--evidence' => 'VFMS receipt 42',
    ])->assertSuccessful();
    expect(vfmsMessages($t->a)[0]->status)->toBe('delivered')
        ->and($checkIns)->toBe(1);
})->with([
    'timeout' => [fn () => throw new ConnectionException('Report timed out')],
    'connection error' => [fn () => Http::failedConnection()],
    'server error' => [fn () => Http::response([], 503)],
    'refused credentials' => [fn () => Http::response([], 401)],
    'not found endpoint' => [fn () => Http::response([], 404)],
    'non JSON' => [fn () => Http::response('upstream unavailable', 200)],
    'unexpected envelope' => [fn () => Http::response(['data' => []], 200)],
    'missing reference' => [fn () => Http::response([['receiptNumber' => 'VFMS-42']], 200)],
    'blank reference' => [fn () => Http::response([['referenceNumber' => '', 'receiptNumber' => 'VFMS-42']], 200)],
    'missing receipt' => [fn (Request $request) => Http::response([['referenceNumber' => $request['refno']]], 200)],
    'blank receipt' => [fn (Request $request) => Http::response([['referenceNumber' => $request['refno'], 'receiptNumber' => '']], 200)],
    'duplicate match' => [fn (Request $request) => Http::response([
        ['referenceNumber' => $request['refno'], 'receiptNumber' => 'VFMS-42'],
        ['referenceNumber' => $request['refno'], 'receiptNumber' => 'VFMS-43'],
    ], 200)],
]);

// protects=VFMS participates in spec 2 restore reconciliation without declaring replay safe; fails_when=the shared reconciler cannot resolve filing or replays an absent operation blindly; no_claim=missing identity recovery after restore.
test('VFMS report results use the existing restore reconciliation contract', function (bool $found) {
    $t = twoTenants();
    liveCredentials($t->a);
    $lookupAvailable = false;
    Http::fake([
        'gateway.zanrevenue.org/api/accommodation/' => Http::response([], 503),
        'gateway.zanrevenue.org/api/accommodationReport/' => function (Request $request) use (&$lookupAvailable, $found) {
            if (! $lookupAvailable) {
                return Http::response([], 503);
            }

            return Http::response($found ? [[
                'referenceNumber' => $request['refno'],
                'receiptNumber' => 'VFMS-RESTORED-42',
                'guest' => ['documentNo' => 'PRIVATE-REPORT-DOCUMENT'],
            ]] : [], 200);
        },
    ]);
    checkInTwoGuests($t->a);
    $message = vfmsMessages($t->a)[0];
    expect($message->status)->toBe('unknown');
    inTenant($t->a->property, fn () => DB::transaction(fn () => app(RestoreGate::class)->close($t->a->property, 'Restore exercise', $t->a->owner)));
    $lookupAvailable = true;

    $result = app(RestoreReconciler::class)->reconcile($t->a->owner, $t->a->property, 'Check VFMS after restore');

    expect($result)->toEqual(['reconciled' => 1, 'unresolved' => $found ? 0 : 1])
        ->and(vfmsMessages($t->a)[0]->status)->toBe($found ? 'delivered' : 'unknown');
    $evidence = DB::table('outbox_restore_evidence')->where('message_id', $message->id)->sole();
    expect($evidence->outcome)->toBe($found ? 'accepted' : 'not_found')
        ->and(json_encode($evidence))->not->toContain('PRIVATE-REPORT-DOCUMENT')->not->toContain('tok-live');
    if ($found) {
        expect(vfmsMessages($t->a)[0]->provider_references)->toEqual(['mode' => 'live', 'receiptNumber' => 'VFMS-RESTORED-42']);
    }
})->with(['found' => true, 'absent is not proof of safe historical replay' => false]);
