<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Reservations\ReservationLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class ReservationListController extends Controller
{
    public function __invoke(ListReservationsRequest $request, ReservationLists $lists): Response
    {
        // ponytail: Spec 7 will own the open business date; use the property's local date until then.
        $date = $request->validated('date', now(Tenancy::property()?->timezone)->toDateString());
        $list = $request->validated('list', 'arrivals');

        return Inertia::render('reservations/index', [
            'filters' => ['date' => $date, 'list' => $list, 'search' => $request->validated('search', '')],
            'reservations' => $lists->query($list, $date, (string) $request->validated('search', ''))->paginate(50)->withQueryString(),
        ]);
    }
}
