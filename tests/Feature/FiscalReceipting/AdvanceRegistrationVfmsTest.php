<?php

use App\Domains\FiscalReceipting\Adapters\HttpVfms;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\VfmsHttp;

test('advance check-in requires its registered guests and room in the accommodation report', function (string $scenario, string $mode) {
    VfmsHttp::fake();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    config(['services.vfms.live_url' => 'https://vfms-live.example/api/']);
    $credential = VfmsCredential::for(twoTenants()->a->property);
    $credential->forceFill(['mode' => VfmsMode::from($mode), 'integration_id' => 'LIVE-ID', 'token' => 'LIVE-TOKEN']);
    $prefix = (string) (crc32($credential->property_id) % 90000 + 10000);
    $wireReference = $mode === 'sandbox' ? 'T'.$prefix.'-REF-171' : 'REF-171';
    $wireRoom = $mode === 'sandbox' ? (int) ($prefix.'0042') : 42;
    $report = [
        'referenceNumber' => $wireReference.'-5940', 'currency' => 'USD',
        'numberOfGuest' => 2, 'isClosed' => false, 'isCanceled' => false,
        'guest' => [
            ['documentNo' => 'P-1', 'isChackedOut' => false],
            ['documentNo' => 'P-2', 'isChackedOut' => false],
        ],
        'room' => [['room' => (int) ($wireRoom.'5940'), 'isOut' => false]],
    ];
    $report = match ($scenario) {
        'empty relationships' => [...$report, 'guest' => [], 'room' => []],
        'missing guest' => [...$report, 'guest' => [$report['guest'][0]]],
        'wrong guest' => [...$report, 'guest' => [['documentNo' => 'OTHER'], $report['guest'][1]]],
        'departed guest' => [...$report, 'guest' => [array_replace($report['guest'][0], ['isChackedOut' => true]), $report['guest'][1]]],
        'wrong room' => [...$report, 'room' => [['room' => 999, 'isOut' => false]]],
        'departed room' => [...$report, 'room' => [array_replace($report['room'][0], ['isOut' => true])]],
        'wrong reference' => [...$report, 'referenceNumber' => 'OTHER-5940'],
        'closed' => [...$report, 'isClosed' => true],
        'cancelled' => [...$report, 'isCanceled' => true],
        'wrong currency' => [...$report, 'currency' => 'TZS'],
        'application refusal' => [...$report, 'statusCode' => 5000],
        'duplicate guest' => [...$report, 'guest' => [$report['guest'][0], ...$report['guest']]],
        'list', 'duplicate records' => [$report, ...($scenario === 'duplicate records' ? [$report] : [])],
        'malformed' => '{not json',
        default => $report,
    };
    Http::fake([
        '*/accommodation/booking/' => Http::response(['referenceNumber' => $wireReference.'-5940', 'booking' => ['bookingNumber' => 'B-171']]),
        '*/accommodationReport/' => $scenario === 'timeout'
            ? Http::failedConnection()
            : Http::response($report, $scenario === 'server error' ? 500 : ($scenario === 'unauthorized' ? 401 : 200)),
    ]);
    $payload = [
        'mode' => $mode, 'request_type' => 'ACCOMMODATION_BOOKING_REQUEST', 'path' => 'accommodation/booking/',
        'body' => [
            'referenceNumber' => 'REF-171', 'bookingNumber' => 'B-171', 'roomId' => 42, 'salesCurrency' => 'USD',
            'checkInDate' => '2026-11-03T12:00:00+03:00', 'checkOutDate' => '2026-11-06T10:00:00+03:00',
            'guest' => [['documentNo' => 'P-1'], ['documentNo' => 'P-2']],
        ],
    ];

    $result = app(HttpVfms::class)->send($payload, $credential);

    expect($result->outcome)->toBe(in_array($scenario, ['complete', 'list'], true) ? 'accepted' : 'unknown');
    if ($result->outcome === 'unknown') {
        expect($result->references)->toBe([]);
    }
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/accommodationReport/')
        && $request->hasHeader('vfms-request-type', 'ACCOMMODATION_REPORT')
        && $request->hasHeader('vfms-token-id', $mode === 'sandbox' ? 'TEST-TOKEN' : 'LIVE-TOKEN')
        && $request->hasHeader('vfms-intergration-id', $mode === 'sandbox' ? 'TEST-INTEGRATION' : 'LIVE-ID')
        && $request['refno'] === $wireReference.'-5940'
        && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $request['startDate'])
        && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $request['endDate']));
    Http::assertNotSent(fn (Request $request) => $request->url() === VfmsHttp::TEST_URL.'accommodation/'
        || ($mode === 'sandbox' ? str_contains($request->url(), 'vfms-live') : str_contains($request->url(), 'vfms-test')));
    expect(Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/accommodation/booking/')))->toHaveCount(1);
})->with([
    'complete', 'list', 'empty relationships', 'missing guest', 'wrong guest', 'departed guest',
    'wrong room', 'departed room', 'wrong reference', 'closed', 'cancelled', 'wrong currency',
    'application refusal', 'duplicate guest', 'duplicate records', 'malformed', 'timeout', 'server error', 'unauthorized',
])->with(['sandbox', 'live']);
