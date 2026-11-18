<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\FolioSummary;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Inventory\Models\Room;
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
        return $this->present(app(ReservationLists::class)->query($list, $date, $search)->paginate(50)->withQueryString());
    }

    /** @return LengthAwarePaginator<int, Reservation> */
    public function frontDesk(string $list, string $date, string $search): LengthAwarePaginator
    {
        $query = app(ReservationLists::class)->query($list, $date, $search)
            ->select('reservations.*')
            ->selectRaw("CASE
                WHEN status = 'checked_in' AND DATE(departure) < ? THEN 0
                WHEN (status = 'checked_in' AND DATE(departure) = ?) OR (status = 'confirmed' AND DATE(arrival) <= ?) THEN 1
                WHEN status = 'checked_out' THEN 3
                ELSE 2 END AS front_desk_priority", [$date, $date, $date])
            ->withCasts(['front_desk_priority' => 'integer'])
            ->reorder()->orderBy('front_desk_priority')->orderBy('arrival')->orderBy('reservation_number');

        return $this->present($query->paginate(50, ['*'], 'guests_page')->withQueryString());
    }

    /**
     * @param  LengthAwarePaginator<int, Reservation>  $reservations
     * @return LengthAwarePaginator<int, Reservation>
     */
    private function present(LengthAwarePaginator $reservations): LengthAwarePaginator
    {
        $businessDate = BusinessDate::current();
        $reservations->getCollection()->each(fn (Reservation $reservation) => $reservation->setAttribute('is_overstay', $reservation->isOverstay($businessDate))->setAttribute('can_check_in', $reservation->status === ReservationStatus::Confirmed
            && $reservation->arrival->toDateString() <= $businessDate && $reservation->departure->toDateString() > $businessDate && Gate::allows('checkIn', $reservation))
            ->setAttribute('can_check_out', $reservation->status === ReservationStatus::CheckedIn
                && $reservation->departure->toDateString() <= $businessDate && Gate::allows('checkOut', $reservation)));
        $page = $reservations->getCollection();
        $balances = FolioSummary::balances($page);
        $checkoutBalances = FolioSummary::checkoutBalances($page);
        $guests = Reservation::guestNames($page);
        $rooms = Room::query()->whereIn('id', $page->pluck('room_id')->filter())->pluck('number', 'id');
        $page->each(fn (Reservation $reservation) => $reservation->setAttribute('balances', $balances[$reservation->id])
            ->setAttribute('checkout_balances', $checkoutBalances[$reservation->id] ?? [])
            ->setAttribute('guest_name', $guests->get($reservation->id))
            ->setAttribute('room_number', $rooms->get($reservation->room_id)));
        $page->filter(fn (Reservation $reservation) => $reservation->getAttribute('can_check_out'))
            ->each(fn (Reservation $reservation) => $reservation->setAttribute('can_override_balance', Gate::allows('checkOutWithBalance', $reservation)));

        return $reservations;
    }
}
