<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

final class VfmsHttp
{
    public const TEST_URL = 'https://vfms-test.example/api/';

    public static function fake(string $checkoutFixture = 'checkout.json'): void
    {
        config([
            'services.vfms.test_url' => self::TEST_URL,
            'services.vfms.test_integration_id' => 'TEST-INTEGRATION',
            'services.vfms.test_token' => 'TEST-TOKEN',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            self::TEST_URL.'guest/checkout/' => fn (Request $request) => Http::response(array_values(array_filter(
                json_decode(file_get_contents(__DIR__.'/../Fixtures/vfms/'.$checkoutFixture), true, flags: JSON_THROW_ON_ERROR),
                fn (array $report) => in_array($report['guest']['documentNo'], array_column($request['documentNumberList'], 'documentNumber'), true),
            ))),
            self::TEST_URL.'service/' => Http::response(['receiptNumber' => 'TEST-RECEIPT-169', 'responseNumber' => 'TEST-SERVICE-171']),
            self::TEST_URL.'changeRoomPrice/' => fn (Request $request) => Http::response(array_map(
                fn (array $room) => [...$room, 'hotelRoomRoom' => $room['hotelRoomRoom'].'5940'],
                $request['rooms'],
            )),
            self::TEST_URL.'currencyExchangeRate/' => Http::response(['rateUSD' => 2600, 'rateEURO' => 2800, 'rateUSDEURO' => 0.93]),
            self::TEST_URL.'accommodation/' => Http::response(['responseNumber' => 'TEST-ACCOMMODATION-171']),
            // Shapes as ZRA's test server answered on 3 Nov 2026.
            self::TEST_URL.'reservation/booking/' => Http::response(['receiptNumber' => 'TEST-ADVANCE-1', 'referenceNumber' => 'TEST-REFERENCE-1', 'booking' => ['bookingId' => 1, 'bookingNumber' => 'TEST-BOOKING-1', 'amountLeft' => 50.0]]),
            self::TEST_URL.'accommodation/booking/' => fn (Request $request) => Http::response(['referenceNumber' => $request['referenceNumber'], 'booking' => ['bookingId' => 1, 'bookingNumber' => $request['bookingNumber'], 'amountLeft' => 50.0]]),
            self::TEST_URL.'change/room/' => Http::response(['responseNumber' => 'TEST-ROOM-171']),
            self::TEST_URL.'guest/accommodation/' => Http::response(['responseNumber' => 'TEST-GUEST-171']),
            self::TEST_URL.'guest/changeProfile/' => Http::response(['responseNumber' => 'TEST-PROFILE-171']),
        ]);
        self::fakeAdvanceReport();
    }

    public static function fakeAdvanceReport(): void
    {
        Http::fake([
            self::TEST_URL.'accommodationReport/' => function (Request $request) {
                $checkIn = Http::recorded(fn (Request $sent) => $sent->url() === self::TEST_URL.'accommodation/booking/')->last();
                if ($checkIn === null) {
                    return Http::response([]);
                }
                $body = $checkIn[0]->data();

                return Http::response([
                    'referenceNumber' => $request['refno'], 'currency' => $body['salesCurrency'],
                    'isClosed' => false, 'isCanceled' => false,
                    'guest' => array_map(fn (array $guest) => [...$guest, 'isChackedOut' => false], $body['guest']),
                    'room' => [['room' => $body['roomId'], 'isOut' => false]],
                ]);
            },
        ]);
    }
}
