<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
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
        // Guests still in-house, lead first, as the check-out filed them (#164).
        $ids = ReservationGuest::query()->where('reservation_id', $reservation->id)->whereNull('departed_at')->orderBy('id')->pluck('guest_id');
        $names = Guest::query()->whereIn('id', $ids)->pluck('name', 'id');

        return Inertia::render('fiscal-receipting/checkout-report', [
            ...CheckoutReport::of($reservation),
            'stay' => $reservation->stay(),
            'guests' => $ids->map(fn (string $id) => $names[$id])->values()->all(),
            'property' => Property::query()->findOrFail($reservation->property_id)->only(['name', 'address_line', 'city', 'phone', 'email']),
        ]);
    }
}
