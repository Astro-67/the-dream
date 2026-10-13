<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\Actions\ListReservations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class ReservationListController extends Controller
{
    public function __invoke(ListReservationsRequest $request, ListReservations $lists): Response
    {
        // ponytail: Spec 7 will own the open business date; use the property's local date until then.
        $date = $request->validated('date', now(Tenancy::property()?->timezone)->toDateString());
        $list = $request->validated('list', 'arrivals');
        $reservations = $lists->handle($list, $date, (string) $request->validated('search', ''));

        return Inertia::render('reservations/index', [
            'filters' => ['date' => $date, 'list' => $list, 'search' => $request->validated('search', '')],
            'reservations' => $reservations,
        ]);
    }
}
