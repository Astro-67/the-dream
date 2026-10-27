<?php

use App\Domains\FiscalReceipting\Adapters\HttpVfms;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VfmsHttp;

beforeEach(function () {
    VfmsHttp::fake();
});

// protects=missing Sandbox configuration fails closed (#169); fails_when=an incomplete test configuration sends HTTP; no_claim=provider validation.
test('Sandbox refuses incomplete test configuration without HTTP', function (string $key) {
    config(['services.vfms.'.$key => '']);
    $credential = VfmsCredential::for(twoTenants()->a->property);

    $result = app(HttpVfms::class)->send(['mode' => 'sandbox', 'request_type' => 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => []], $credential);

    expect($result->outcome)->toBe('rejected');
    Http::assertNothingSent();
})->with(['test_url', 'test_integration_id', 'test_token']);

// protects=queued work never silently changes environment (#169); fails_when=a recorded/current mode mismatch reaches either endpoint; no_claim=operator recovery workflow.
test('VFMS refuses queued work whose mode no longer matches the property', function (VfmsMode $current, string $recorded) {
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $credential->forceFill(['mode' => $current, 'integration_id' => 'LIVE-ID', 'token' => 'LIVE-TOKEN']);

    $result = app(HttpVfms::class)->send(['mode' => $recorded, 'request_type' => 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => []], $credential);

    expect($result->outcome)->toBe('rejected');
    Http::assertNothingSent();
})->with([[VfmsMode::Sandbox, 'live'], [VfmsMode::Live, 'sandbox']]);

// protects=shared test credentials isolate properties without mutating stored identities (#169); fails_when=room or reference IDs collide, nested room IDs are not prefixed, or the input body changes; no_claim=CRC uniqueness for all properties.
test('Sandbox prefixes room and reference identities on the wire only', function () {
    $tenants = twoTenants();
    $body = ['roomId' => 42, 'referenceNumber' => 'R-169-2', 'rooms' => [['hotelRoomRoom' => 42, 'roomPrice' => 110]]];
    foreach ([$tenants->a->property, $tenants->b->property] as $property) {
        $credential = VfmsCredential::for($property);
        expect($credential->property_id)->toBe($property->id)
            ->and($credential->organization_id)->toBe($property->organization_id);
        $payload = ['mode' => 'sandbox', 'request_type' => 'CHANGEROOM_PRICE', 'path' => 'changeRoomPrice/', 'body' => $body];
        $prefix = (string) (crc32($property->id) % 90000 + 10000);
        $room = (int) ($prefix.'0042');

        $result = app(HttpVfms::class)->send($payload, $credential);

        expect($result->outcome)->toBe('accepted')->and($payload['body'])->toEqual($body);
        Http::assertSent(fn (Request $request) => $request->url() === VfmsHttp::TEST_URL.'changeRoomPrice/'
            && $request['roomId'] === $room
            && $request['rooms'][0]['hotelRoomRoom'] === $room
            && $request['referenceNumber'] === 'T'.$prefix.'-R-169-2');
    }
    Http::assertSentCount(2);
});

// protects=Sandbox room identity composition is unambiguous (#169); fails_when=IDs outside the four-digit range send HTTP; no_claim=Live room ID limits.
test('Sandbox refuses room IDs outside the supported identity range', function (int $id, bool $nested) {
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $body = $nested ? ['rooms' => [['hotelRoomRoom' => $id, 'roomPrice' => 110]]] : ['roomId' => $id];

    $result = app(HttpVfms::class)->send(['mode' => 'sandbox', 'request_type' => $nested ? 'CHANGEROOM_PRICE' : 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => $body], $credential);

    expect($result->outcome)->toBe('rejected');
    Http::assertNothingSent();
})->with([[0, false], [10000, false], [0, true], [10000, true]]);

// protects=Live uses its configured endpoint and property credentials with unchanged identities (#169); fails_when=Live uses test headers or prefixes; no_claim=provider acceptance.
test('Live uses its configured URL and leaves wire identities unchanged', function () {
    config(['services.vfms.live_url' => 'https://vfms-live.example/custom/']);
    Http::fake(['https://vfms-live.example/custom/accommodation/' => Http::response(['responseNumber' => 'LIVE-169'])]);
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $credential->forceFill(['mode' => VfmsMode::Live, 'integration_id' => 'LIVE-ID', 'token' => 'LIVE-TOKEN']);
    $body = ['roomId' => 42, 'referenceNumber' => 'R-169-2'];

    $result = app(HttpVfms::class)->send(['mode' => 'live', 'request_type' => 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => $body], $credential);

    expect($result->outcome)->toBe('accepted');
    Http::assertSent(fn (Request $request) => $request->url() === 'https://vfms-live.example/custom/accommodation/'
        && $request->header('vfms-intergration-id') === ['LIVE-ID']
        && $request->header('vfms-token-id') === ['LIVE-TOKEN']
        && $request->data() === $body);
    Http::assertSentCount(1);
});

// protects=Live credentials never travel over cleartext HTTP (#169); fails_when=an HTTP Live endpoint receives any request; no_claim=operator endpoint ownership.
test('Live refuses an endpoint without HTTPS', function () {
    config(['services.vfms.live_url' => 'http://vfms-live.example/api/']);
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $credential->forceFill(['mode' => VfmsMode::Live, 'integration_id' => 'LIVE-ID', 'token' => 'LIVE-TOKEN']);

    $result = app(HttpVfms::class)->send(['mode' => 'live', 'request_type' => 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => []], $credential);

    expect($result->outcome)->toBe('rejected');
    Http::assertNothingSent();
});

// protects=test-unit refusal never poisons a property's Live credentials (#169); fails_when=a Sandbox 401 marks property credentials refused; no_claim=Live 401 handling (CheckInVfmsTest).
test('Sandbox authentication failure does not mark property credentials refused', function () {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([VfmsHttp::TEST_URL.'accommodation/' => Http::response([], 401)]);
    $property = twoTenants()->a->property;
    $credential = inTenant($property, fn () => VfmsCredential::query()->create(['integration_id' => 'PROPERTY-ID', 'token' => 'PROPERTY-TOKEN']));

    $result = app(HttpVfms::class)->send(['mode' => 'sandbox', 'request_type' => 'ACCOMMODATION_REQUEST', 'path' => 'accommodation/', 'body' => []], $credential);

    expect($result->outcome)->toBe('rejected')
        ->and(inTenant($property, fn () => $credential->fresh()->credentials_refused_at))->toBeNull();
    Http::assertSentCount(1);
});

// protects=HTTP success alone is not fiscal evidence (#171); fails_when=invalid or refused answers are accepted or duplicate answers are re-file-able; no_claim=checkout guest-array decoding (#172).
test('VFMS validates operation-specific success evidence and retains the answer', function (string $operation, mixed $answer, string $outcome, array $references) {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['https://vfms-live.example/api/*' => Http::response($answer)]);
    config(['services.vfms.live_url' => 'https://vfms-live.example/api/']);
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $credential->forceFill(['mode' => VfmsMode::Live, 'integration_id' => 'LIVE-ID', 'token' => 'LIVE-TOKEN']);
    $request = ['referenceNumber' => 'REF-171', 'rooms' => [['hotelRoomRoom' => 42, 'roomPrice' => 110]]];

    $result = app(HttpVfms::class)->send(['mode' => 'live', 'request_type' => $operation, 'path' => 'operation/', 'body' => $request], $credential);

    expect($result->outcome)->toBe($outcome)
        ->and($result->references)->toEqual($references)
        ->and($result->responseBody)->toEqual(['status' => 200, 'body' => is_string($answer) ? (json_decode($answer, true) ?? $answer) : $answer]);
    Http::assertSentCount(1);
})->with([
    'accommodation response' => ['ACCOMMODATION_REQUEST', ['responseNumber' => 'AC-171'], 'accepted', ['responseNumber' => 'AC-171']],
    'room response' => ['ACCOMMODATION_CHANGE_ROOM', ['responseNumber' => 'ROOM-171'], 'accepted', ['responseNumber' => 'ROOM-171']],
    'guest response' => ['ACCOMMODATION_ADD_GUEST', ['responseNumber' => 'GUEST-171'], 'accepted', ['responseNumber' => 'GUEST-171']],
    'profile response' => ['GUEST_PROFILE', ['responseNumber' => 'PROFILE-171'], 'accepted', ['responseNumber' => 'PROFILE-171']],
    'receipt is not accommodation response' => ['ACCOMMODATION_REQUEST', ['receiptNumber' => 'RECEIPT-171'], 'unknown', []],
    'receipt is not room response' => ['ACCOMMODATION_CHANGE_ROOM', ['receiptNumber' => 'RECEIPT-171'], 'unknown', []],
    'receipt is not guest response' => ['ACCOMMODATION_ADD_GUEST', ['receiptNumber' => 'RECEIPT-171'], 'unknown', []],
    'receipt is not profile response' => ['GUEST_PROFILE', ['receiptNumber' => 'RECEIPT-171'], 'unknown', []],
    'blank response' => ['ACCOMMODATION_REQUEST', ['responseNumber' => ' '], 'unknown', []],
    'non scalar response' => ['ACCOMMODATION_REQUEST', ['responseNumber' => ['AC-171']], 'unknown', []],
    'empty' => ['ACCOMMODATION_REQUEST', [], 'unknown', []],
    'unrelated' => ['ACCOMMODATION_REQUEST', ['ok' => true], 'unknown', []],
    'malformed' => ['ACCOMMODATION_REQUEST', '{not json', 'unknown', []],
    'application refusal' => ['ACCOMMODATION_REQUEST', ['statusCode' => 5000, 'statusMessage' => 'The Room ID 42 Is not Empty'], 'rejected', []],
    'string application refusal' => ['ACCOMMODATION_REQUEST', ['statusCode' => '5000', 'statusMessage' => 'Guest Not Found'], 'rejected', []],
    'failure overrides evidence' => ['ACCOMMODATION_REQUEST', ['statusCode' => 5000, 'responseNumber' => 'AC-171'], 'rejected', []],
    'same duplicate' => ['ACCOMMODATION_REQUEST', ['statusCode' => 5000, 'statusMessage' => 'Reference number REF-171-5940 already exists'], 'unknown', []],
    'different duplicate' => ['ACCOMMODATION_REQUEST', ['statusCode' => 5000, 'statusMessage' => 'Reference number OTHER-5940 already exists'], 'unknown', []],
    'duplicate with receipt' => ['ACCOMMODATION_ADD_SERVICE', ['statusMessage' => 'Duplicate reference', 'receiptNumber' => 'RECEIPT-171'], 'unknown', []],
    'service receipt' => ['ACCOMMODATION_ADD_SERVICE', ['receiptNumber' => 'RECEIPT-171'], 'accepted', ['receiptNumber' => 'RECEIPT-171']],
    'service response and receipt' => ['ACCOMMODATION_ADD_SERVICE', ['receiptNumber' => 'RECEIPT-171', 'responseNumber' => 'SERVICE-171'], 'accepted', ['receiptNumber' => 'RECEIPT-171', 'responseNumber' => 'SERVICE-171']],
    'service response only' => ['ACCOMMODATION_ADD_SERVICE', ['responseNumber' => 'SERVICE-171'], 'unknown', []],
    'blank service receipt' => ['ACCOMMODATION_ADD_SERVICE', ['receiptNumber' => ' '], 'unknown', []],
    'invalid optional response' => ['ACCOMMODATION_ADD_SERVICE', ['receiptNumber' => 'RECEIPT-171', 'responseNumber' => null], 'unknown', []],
    'price bare list' => ['CHANGEROOM_PRICE', [['hotelRoomRoom' => '425940', 'roomPrice' => 110]], 'accepted', []],
    'price wrong room' => ['CHANGEROOM_PRICE', [['hotelRoomRoom' => '435940', 'roomPrice' => 110]], 'unknown', []],
    'price wrong amount' => ['CHANGEROOM_PRICE', [['hotelRoomRoom' => '425940', 'roomPrice' => 0]], 'unknown', []],
    'price missing amount' => ['CHANGEROOM_PRICE', [['hotelRoomRoom' => '425940']], 'unknown', []],
    'price invalid amount' => ['CHANGEROOM_PRICE', [['hotelRoomRoom' => '425940', 'roomPrice' => 'INF']], 'unknown', []],
    'price empty list' => ['CHANGEROOM_PRICE', [], 'unknown', []],
    'price object pretending to be list' => ['CHANGEROOM_PRICE', '{"0":{"hotelRoomRoom":"425940","roomPrice":110}}', 'unknown', []],
    'price number alone' => ['CHANGEROOM_PRICE', ['responseNumber' => 'PRICE-171'], 'unknown', []],
    'rates' => ['CURRENCY_EXCHANGE', ['rateUSD' => 2600, 'rateEURO' => '2800', 'rateUSDEURO' => 0.93], 'accepted', ['rateUSD' => 2600, 'rateEURO' => '2800', 'rateUSDEURO' => 0.93]],
    'incomplete rates' => ['CURRENCY_EXCHANGE', ['rateUSD' => 2600], 'unknown', []],
    'invalid rates' => ['CURRENCY_EXCHANGE', ['rateUSD' => 'NaN', 'rateEURO' => 2800, 'rateUSDEURO' => 0.93], 'unknown', []],
    'negative rates' => ['CURRENCY_EXCHANGE', ['rateUSD' => -1, 'rateEURO' => 2800, 'rateUSDEURO' => 0.93], 'unknown', []],
    'nonfinite rates' => ['CURRENCY_EXCHANGE', '{"rateUSD":1e999,"rateEURO":2800,"rateUSDEURO":0.93}', 'unknown', []],
    'legacy checkout' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => 'CO-171', 'totalAmount' => 220, 'totalTax' => 0, 'infrastructureTax' => 0], 'unknown', []],
    'checkout missing totals' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => 'CO-171'], 'unknown', []],
    'checkout missing levy' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => 'CO-171', 'totalAmount' => 220, 'totalTax' => 0], 'unknown', []],
    'checkout invalid totals' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => 'CO-171', 'totalAmount' => 'NaN', 'totalTax' => 0, 'infrastructureTax' => 0], 'unknown', []],
    'checkout negative totals' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => 'CO-171', 'totalAmount' => -1, 'totalTax' => 0, 'infrastructureTax' => 0], 'unknown', []],
    'checkout blank report' => ['ACCOMMODATION_CHECK_OUT', ['reportNo' => ' ', 'totalAmount' => 220, 'totalTax' => 0, 'infrastructureTax' => 0], 'unknown', []],
    'checkout per guest array' => ['ACCOMMODATION_CHECK_OUT', [['responseNumber' => 'GUEST-171', 'totalAmount' => 110]], 'unknown', []],
    'unknown operation' => ['OTHER', ['responseNumber' => 'OTHER-171'], 'unknown', []],
]);
