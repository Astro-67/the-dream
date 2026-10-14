<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\Reports\PropertyKpis;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class PropertyReportController extends Controller
{
    public function __invoke(ReportRequest $request, PropertyKpis $kpis): Response
    {
        /** @var array{from: string, to: string} $range */
        $range = $request->validated();

        return Inertia::render('reports/index', [
            'filters' => $range,
            'kpis' => $kpis->forProperty(Tenancy::property() ?? abort(404), $range['from'], $range['to']),
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
