<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\FolioSummary;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Guests\Models\Booker;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
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
        $businessDate = BusinessDate::current();
        $reservations = app(ReservationLists::class)->query($list, $date, $search)->paginate(50)->withQueryString();
        $reservations->getCollection()->each(fn (Reservation $reservation) => $reservation->setAttribute('is_overstay', $reservation->isOverstay($businessDate))->setAttribute('can_check_in', $reservation->status === ReservationStatus::Confirmed
            && $reservation->arrival->toDateString() <= $businessDate && $reservation->departure->toDateString() > $businessDate && Gate::allows('checkIn', $reservation))
            ->setAttribute('can_check_out', $reservation->status === ReservationStatus::CheckedIn
                && $reservation->departure->toDateString() <= $businessDate && Gate::allows('checkOut', $reservation)));
        $page = $reservations->getCollection();
        $balances = FolioSummary::balances($page);
        $guests = ReservationGuest::query()->whereIn('reservation_id', $page->modelKeys())->orderBy('id')->get(['reservation_id', 'guest_id'])->unique('reservation_id');
        $names = Guest::query()->whereIn('id', $guests->pluck('guest_id'))->pluck('name', 'id');
        $bookers = Booker::query()->whereIn('id', $page->pluck('booker_id')->filter())->pluck('name', 'id');
        $rooms = Room::query()->whereIn('id', $page->pluck('room_id')->filter())->pluck('number', 'id');
        $leads = $guests->mapWithKeys(fn (ReservationGuest $link) => [$link->getAttribute('reservation_id') => $names->get($link->getAttribute('guest_id'))]);
        $page->each(fn (Reservation $reservation) => $reservation->setAttribute('balances', $balances[$reservation->id])
            // The booker is only a fallback for stays booked before the lead guest was taken inline.
            ->setAttribute('guest_name', $leads->get($reservation->id) ?? $bookers->get($reservation->getAttribute('booker_id')))
            ->setAttribute('room_number', $rooms->get($reservation->room_id)));
        $page->filter(fn (Reservation $reservation) => $reservation->getAttribute('can_check_out'))
            ->each(fn (Reservation $reservation) => $reservation->setAttribute('can_override_balance', Gate::allows('checkOutWithBalance', $reservation)));

        return $reservations;
    }
}
