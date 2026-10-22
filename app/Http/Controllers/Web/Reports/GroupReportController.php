<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\Reports\GroupReports;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\GroupReportRequest;
use Inertia\Inertia;
use Inertia\Response;

class GroupReportController extends Controller
{
    public function __invoke(GroupReportRequest $request, GroupReports $reports): Response
    {
        /** @var array{from: string, to: string, properties?: list<string>, settled?: bool} $filters */
        $filters = $request->validated();
        $settled = (bool) ($filters['settled'] ?? false);

        return Inertia::render('reports/group', [
            'filters' => ['from' => $filters['from'], 'to' => $filters['to'], 'properties' => $filters['properties'] ?? [], 'settled' => $settled],
            'available' => $request->available()->map(fn (Property $property) => ['id' => $property->id, 'name' => $property->name])->all(),
            ...$reports->forProperties($request->properties(), $filters['from'], $filters['to'], $settled),
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
