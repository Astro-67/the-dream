<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\Reports\PropertyKpis;
use App\Domains\Reports\PropertyOperations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class PropertyReportController extends Controller
{
    public function __invoke(ReportRequest $request, PropertyKpis $kpis, PropertyOperations $operations): Response
    {
        /** @var array{from: string, to: string} $range */
        $range = $request->validated();
        $property = Tenancy::property() ?? abort(404);

        return Inertia::render('reports/index', [
            'filters' => $range,
            'kpis' => $kpis->forProperty($property, $range['from'], $range['to']),
            'operations' => $operations->forProperty($property, $range['from'], $range['to']),
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
