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
        /** @var array{from: string, to: string, properties?: list<string>} $filters */
        $filters = $request->validated();

        return Inertia::render('reports/group', [
            'filters' => ['from' => $filters['from'], 'to' => $filters['to'], 'properties' => $filters['properties'] ?? []],
            'available' => $request->available()->map(fn (Property $property) => ['id' => $property->id, 'name' => $property->name])->all(),
            ...$reports->forProperties($request->properties(), $filters['from'], $filters['to']),
            'refreshedAt' => now()->toIso8601String(),
        ]);
    }
}
