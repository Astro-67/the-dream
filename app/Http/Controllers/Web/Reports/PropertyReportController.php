<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\Billing\FiscalExceptions;
use App\Domains\Reports\PropertyFinance;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reports\PropertyOperations;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReportRequest;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class PropertyReportController extends Controller
{
    public function __invoke(ReportRequest $request, PropertyKpis $kpis, PropertyOperations $operations, PropertyFinance $finance): Response
    {
        /** @var array{from: string, to: string, settled?: bool} $range */
        $range = $request->validated();
        $settled = (bool) ($range['settled'] ?? false);
        $property = Tenancy::property() ?? abort(404);

        return Inertia::render('reports/index', [
            'filters' => ['from' => $range['from'], 'to' => $range['to'], 'settled' => $settled],
            'kpis' => $kpis->forProperty($property, $range['from'], $range['to']),
            'operations' => $operations->forProperty($property, $range['from'], $range['to']),
            'finance' => $finance->forProperty($property, $range['from'], $range['to'], $settled),
            'fiscalExceptions' => Access::allows($request->user(), OrganizationPermission::FiscalExceptionsReview, $property) ? FiscalExceptions::of($property) : null,
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
