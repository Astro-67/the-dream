<?php

namespace App\Http\Controllers\Web\Reports;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Reports\Collections;
use App\Domains\Reports\PropertyFinance;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\PaymentsRequest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentsController extends Controller
{
    public function __invoke(PaymentsRequest $request, Collections $collections, PropertyFinance $finance): Response
    {
        /** @var array{from: string, to: string, period: string, view: string, settled: bool, reservation?: string} $filters */
        $filters = [...$request->validated(), 'settled' => (bool) $request->validated('settled', false)];
        $property = Tenancy::property() ?? abort(404);

        return Inertia::render('payments/index', [
            'filters' => $filters,
            'reservationNumber' => isset($filters['reservation']) ? Reservation::query()->where('property_id', $property->id)->findOrFail($filters['reservation'])->reservation_number : null,
            'received' => $filters['view'] === 'received' ? $collections->received($property, $filters['from'], $filters['to'], $filters['reservation'] ?? null) : null,
            'payments' => $filters['view'] === 'received' ? $collections->payments($property, $filters['from'], $filters['to'], $filters['reservation'] ?? null) : null,
            'folios' => $filters['view'] === 'outstanding' ? $finance->folios(collect([$property]), settled: $filters['settled'], from: $filters['from'], to: $filters['to']) : null,
            'refreshedAt' => now()->toIso8601String(),
            'cashierShiftHistoryUrl' => Gate::allows('viewAny', CashierShift::class)
                && $collections->cashierShiftHistoryAvailable($property)
                    ? route('cashier-shifts.index') : null,
        ]);
    }
}
