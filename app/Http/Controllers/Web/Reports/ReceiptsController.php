<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\FiscalReceipting\IssuedDocuments;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Models\Reservation;
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
        $page = $documents->register($property, $filters);
        $reservations = Reservation::query()->where('property_id', $property->id)
            ->whereIn('id', collect($page->items())->pluck('reservationId'))->pluck('reservation_number', 'id');

        return Inertia::render('receipts/index', [
            'filters' => $filters,
            'documents' => $page->through(fn (array $row) => [
                ...$row,
                'mode' => $row['type'] === 'non_fiscal' ? null : $row['zraStatus'],
                'localUrl' => $row['type'] === 'non_fiscal'
                    ? route('receipts.show', $row['id'])
                    : (isset($reservations[$row['reservationId']])
                        ? route('reservations.fiscal-report', $reservations[$row['reservationId']]).'#document-'.$row['type'].'-'.rawurlencode($row['number'])
                        : null),
            ]),
        ]);
    }
}
