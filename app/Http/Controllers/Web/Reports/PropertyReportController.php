<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\FiscalReceipting\VfmsFilings;
use App\Domains\Reports\PropertyFinance;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class PropertyReportController extends Controller
{
    public function __invoke(ReportRequest $request, PropertyKpis $kpis, PropertyOperations $operations, PropertyFinance $finance, VfmsFilings $filings): Response
    {
        /** @var array{from: string, to: string, settled?: bool, view: string} $range */
        $range = $request->validated();
        $settled = (bool) ($range['settled'] ?? false);
        $property = Tenancy::property() ?? abort(404);
        $overview = $range['view'] === 'overview';

        return Inertia::render('reports/index', [
            'filters' => ['from' => $range['from'], 'to' => $range['to'], 'settled' => $settled, 'view' => $range['view']],
            'filingsAvailable' => JurisdictionRules::usesVfms($property),
            'kpis' => $overview ? $kpis->forProperty($property, $range['from'], $range['to']) : null,
            'operations' => $overview ? $operations->forProperty($property, $range['from'], $range['to']) : null,
            'finance' => $overview ? $finance->forProperty($property, $range['from'], $range['to'], $settled) : null,
            'filings' => $overview ? null : $filings->forProperty($property, $range['from'], $range['to']),
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
