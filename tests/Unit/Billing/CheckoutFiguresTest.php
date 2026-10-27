<?php

use App\Domains\FiscalReceipting\CheckoutFigures;
use Tests\TestCase;

uses(TestCase::class);

/** @return list<array<string, mixed>> */
function recordedCheckoutFigures(): array
{
    return json_decode(file_get_contents(__DIR__.'/../../Fixtures/vfms/checkout-two-guests.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** @return array{currency: string, documentNumberList: list<array{documentNumber: string}>} */
function checkoutFiguresRequest(): array
{
    return ['currency' => 'USD', 'documentNumberList' => [['documentNumber' => 'TEST-DOC-1'], ['documentNumber' => 'TEST-DOC-2']]];
}

// protects=shared room is counted once while services and levy remain per guest (#172); fails_when=room or VAT is doubled, or a guest levy is omitted; no_claim=production report schema or tax correctness.
test('recorded two-guest checkout figures count the room once', function () {
    expect(app(CheckoutFigures::class)->of(recordedCheckoutFigures(), checkoutFiguresRequest()))->toEqual([
        'room' => 10000, 'extras' => 2000, 'tax' => 1565, 'levy' => 1000, 'total' => 13000,
        'numbers' => ['TEST-REPORT-1', 'TEST-REPORT-2'],
    ]);
});

// protects=incomplete or inconsistent provider figures never settle a folio (#172); fails_when=unknown money or mismatched guest evidence produces a total; no_claim=provider report completeness beyond checked fields.
test('checkout figures refuse incomplete or inconsistent evidence', function (Closure $change) {
    $reports = recordedCheckoutFigures();
    $reports = $change($reports);

    expect(app(CheckoutFigures::class)->of($reports, checkoutFiguresRequest()))->toBeNull();
})->with([
    'missing guest' => [fn (array $r) => [$r[0]]],
    'wrong guest' => [function (array $r) {
        $r[1]['guest']['documentNo'] = 'OTHER';

        return $r;
    }],
    'duplicate guest' => [fn (array $r) => [$r[0], $r[0]]],
    'different currency' => [function (array $r) {
        $r[1]['accommodation']['currency'] = 'TZS';

        return $r;
    }],
    'missing tax' => [function (array $r) {
        unset($r[0]['total']['totalTax']);

        return $r;
    }],
    'negative levy' => [function (array $r) {
        $r[0]['totalInfrastructure'] = -5;

        return $r;
    }],
    'out of range levy' => [function (array $r) {
        $r[0]['totalInfrastructure'] = 1e100;

        return $r;
    }],
    'changed room tax' => [function (array $r) {
        $r[1]['room']['room'][0]['tax'] = 14;

        return $r;
    }],
    'wrong total' => [function (array $r) {
        $r[0]['total']['totalAmount'] = 200;

        return $r;
    }],
    'malformed guest name' => [function (array $r) {
        $r[0]['guest']['fullName'] = [];

        return $r;
    }],
    'repeated room within guest' => [function (array $r) {
        $r[0]['room']['room'][] = $r[0]['room']['room'][0];
        $r[0]['total']['totalAmount'] += 100;
        $r[0]['total']['totalTax'] += 13.04;

        return $r;
    }],
    'empty list' => [fn (array $r) => []],
]);

// protects=provider aggregates fit the integer money ledger (#172); fails_when=many individually valid levies overflow to an accepted total; no_claim=currency conversion.
test('checkout figures refuse an overflowing aggregate', function () {
    $reports = [];
    $request = ['currency' => 'USD', 'documentNumberList' => []];
    for ($index = 0; $index < 10; $index++) {
        $report = recordedCheckoutFigures()[0];
        $report['reportNumber'] = 'REPORT-'.$index;
        $report['guest']['documentNo'] = 'DOCUMENT-'.$index;
        $report['totalInfrastructure'] = 1e16;
        $reports[] = $report;
        $request['documentNumberList'][] = ['documentNumber' => 'DOCUMENT-'.$index];
    }

    expect(app(CheckoutFigures::class)->of($reports, $request))->toBeNull();
});
