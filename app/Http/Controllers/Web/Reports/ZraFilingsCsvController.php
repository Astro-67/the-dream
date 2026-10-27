<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\FiscalReceipting\VfmsFilings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Response;

class ZraFilingsCsvController extends Controller
{
    public function __invoke(ReportRequest $request, VfmsFilings $filings): Response
    {
        /** @var array{from: string, to: string} $range */
        $range = $request->validated();
        $property = Tenancy::property() ?? abort(404);
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, [__('Business date'), __('Reservation'), __('Call'), __('Currency'), __('Amount'), __('Status'), __('ZRA number')], escape: '');
        foreach ($filings->rows($property, $range['from'], $range['to']) as $row) {
            fputcsv($csv, [
                $row['date'], $row['reservationNumber'], $row['call'], $row['currency'],
                $row['amount'] === null || $row['currency'] === null ? null : VfmsFilings::decimal($row['amount'], $row['currency']),
                $row['statusLabel'], $row['zraNumber'],
            ], escape: '');
        }
        rewind($csv);

        return response((string) stream_get_contents($csv), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"zra-filings-{$range['from']}-{$range['to']}.csv\"",
        ]);
    }
}
