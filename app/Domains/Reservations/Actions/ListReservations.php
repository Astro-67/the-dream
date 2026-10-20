<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\ReservationLists;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;

class ListReservations
{
    /** @return LengthAwarePaginator<int, Reservation> */
    public function handle(string $list, string $date, string $search): LengthAwarePaginator
    {
        $businessDate = BusinessDate::current();
        $reservations = app(ReservationLists::class)->query($list, $date, $search)->paginate(50)->withQueryString();
        $reservations->getCollection()->each(fn (Reservation $reservation) => $reservation->setAttribute('is_overstay', $reservation->isOverstay($businessDate))->setAttribute('can_check_in', $reservation->status === ReservationStatus::Confirmed
            && $reservation->arrival->toDateString() === $businessDate && Gate::allows('checkIn', $reservation))
            ->setAttribute('can_check_out', $reservation->status === ReservationStatus::CheckedIn
                && $reservation->departure->toDateString() <= $businessDate && Gate::allows('checkOut', $reservation)));
        $leaving = $reservations->getCollection()->filter(fn (Reservation $reservation) => $reservation->getAttribute('can_check_out'));
        $folios = Folio::query()->whereIn('reservation_id', $leaving->modelKeys())->withSum('postings as balance_amount', 'amount')->get()->groupBy('reservation_id');
        $leaving->each(fn (Reservation $reservation) => $reservation->setAttribute('can_override_balance', Gate::allows('checkOutWithBalance', $reservation))
            ->setAttribute('balances', $folios->get($reservation->id, collect())->groupBy(fn (Folio $folio) => $folio->currency->value)
                ->map(fn ($group, string $currency) => ['currency' => $currency, 'balance' => (int) $group->sum('balance_amount')])->values()->all()));

        return $reservations;
    }
}
