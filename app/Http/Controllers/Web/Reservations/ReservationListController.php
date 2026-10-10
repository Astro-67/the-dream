<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Support\Tenancy\Tenancy;
use Inertia\Inertia;
use Inertia\Response;

class ReservationListController extends Controller
{
    public function __invoke(ListReservationsRequest $request): Response
    {
        // ponytail: Spec 7 will own the open business date; use the property's local date until then.
        $date = $request->validated('date', now(Tenancy::property()?->timezone)->toDateString());
        $list = $request->validated('list', 'arrivals');
        $query = Reservation::query();
        match ($list) {
            'arrivals' => $query->whereDate('arrival', $date)->whereIn('status', ['confirmed', 'checked_in']),
            'departures' => $query->whereDate('departure', $date)->whereIn('status', ['confirmed', 'checked_in', 'checked_out']),
            'in-house' => $query->where('status', 'checked_in')->whereDate('arrival', '<=', $date)->whereDate('departure', '>', $date),
            default => null,
        };

        $search = trim((string) $request->validated('search', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('reservation_number', 'like', '%'.$search.'%')
                    ->orWhereIn('id', ReservationGuest::query()->select('reservation_id')
                        ->whereIn('guest_id', Guest::query()->select('id')->where('name', 'like', '%'.$search.'%')));
            });
        }

        return Inertia::render('reservations/index', [
            'filters' => ['date' => $date, 'list' => $list, 'search' => $request->validated('search', '')],
            'reservations' => $query->orderBy('arrival')->orderBy('reservation_number')->paginate(50)->withQueryString(),
        ]);
    }
}
