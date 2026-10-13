<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\ReservationLists;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\ListReservationsRequest;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationListController extends Controller
{
    public function __invoke(ListReservationsRequest $request, ReservationLists $lists): Response
    {
        // ponytail: Spec 7 will own the open business date; use the property's local date until then.
        $date = $request->validated('date', now(Tenancy::property()?->timezone)->toDateString());
        $list = $request->validated('list', 'arrivals');
        $today = now(Tenancy::property()?->timezone)->toDateString();
        $reservations = $lists->query($list, $date, (string) $request->validated('search', ''))->paginate(50)->withQueryString();
        $reservations->getCollection()->each(fn (Reservation $reservation) => $reservation->setAttribute('can_check_in', $reservation->status === ReservationStatus::Confirmed
            && $reservation->arrival->toDateString() === $today && Gate::allows('checkIn', $reservation))
            ->setAttribute('can_check_out', $reservation->status === ReservationStatus::CheckedIn
                && $reservation->departure->toDateString() <= $today && Gate::allows('checkOut', $reservation)));
        // The check-out dialog shows each currency's balance and whether it may be overridden.
        $leaving = $reservations->getCollection()->filter(fn (Reservation $reservation) => $reservation->getAttribute('can_check_out'));
        $folios = Folio::query()->whereIn('reservation_id', $leaving->modelKeys())->with('postings')->get()->groupBy('reservation_id');
        $leaving->each(fn (Reservation $reservation) => $reservation->setAttribute('can_override_balance', Gate::allows('checkOutWithBalance', $reservation))
            ->setAttribute('balances', $folios->get($reservation->id, collect())->groupBy(fn (Folio $folio) => $folio->currency->value)
                ->map(fn ($group, string $currency) => ['currency' => $currency, 'balance' => (int) $group->sum(fn (Folio $folio) => $folio->balance()->amount)])->values()->all()));

        return Inertia::render('reservations/index', [
            'filters' => ['date' => $date, 'list' => $list, 'search' => $request->validated('search', '')],
            'reservations' => $reservations,
        ]);
    }
}
