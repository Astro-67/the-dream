<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\Reports\PropertyKpis;
use App\Http\Controllers\Controller;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptsController extends Controller
{
    public function __invoke(Request $request, IssuedDocuments $documents): Response
    {
        Gate::authorize('view', PropertyKpis::class);
        $property = Tenancy::property() ?? abort(404);
        $filters = $request->only(['from', 'to', 'type', 'zra_status']);

        return Inertia::render('receipts/index', [
            'filters' => $filters,
            'documents' => $documents->register($property, $filters),
        ]);
    }
}
