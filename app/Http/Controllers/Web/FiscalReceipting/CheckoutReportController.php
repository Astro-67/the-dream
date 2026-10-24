<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\FiscalReceipting\VfmsPayloads;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutReportController extends Controller
{
    /** Guest-facing, like the folio statement: names only, never identity documents. */
    public function __invoke(Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return Inertia::render('fiscal-receipting/checkout-report', [
            ...CheckoutReport::of($reservation),
            'stay' => $reservation->stay(),
            'guests' => array_column(app(VfmsPayloads::class)->guests($reservation), 'fullName'),
            'property' => Property::query()->findOrFail($reservation->property_id)->only(['name', 'address_line', 'city', 'phone', 'email']),
        ]);
    }
}
