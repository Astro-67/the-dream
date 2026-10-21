<?php

namespace App\Domains\Reservations;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

class ReservationLists
{
    public const FILTERS = ['upcoming', 'today', 'in_house', 'past', 'all', 'arrivals', 'departures', 'in-house', 'overstays', 'balance-owing', 'no-id-document'];

    /** @return Builder<Reservation> */
    public function query(string $list, string $date, string $search): Builder
    {
        $query = Reservation::query();
        match ($list) {
            'upcoming' => $query->where('status', 'confirmed')->whereDate('arrival', '>', $date),
            'past' => $query->whereIn('status', ['checked_out', 'cancelled', 'no_show', 'void']),
            'today', 'arrivals' => $query->whereDate('arrival', $date)->whereIn('status', ['confirmed', 'checked_in']),
            'departures' => $query->whereDate('departure', $date)->whereIn('status', ['confirmed', 'checked_in', 'checked_out']),
            'in_house', 'in-house' => $query->where('status', 'checked_in')->whereDate('arrival', '<=', $date),
            'overstays' => $query->where('status', 'checked_in')->whereDate('departure', '<', BusinessDate::current()),
            'balance-owing' => $query->whereIn('id', Folio::query()->select('reservation_id')->whereIn('id', Posting::query()->select('folio_id')->groupBy('folio_id')->havingRaw('SUM(amount) > 0'))),
            'no-id-document' => $query->where(function ($query) {
                $query->whereNotIn('id', ReservationGuest::query()->select('reservation_id'))
                    ->orWhereIn('id', ReservationGuest::query()->select('reservation_id')->whereIn('guest_id', Guest::query()->select('id')->whereDoesntHave('documents')));
            }),
            default => null,
        };

        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('reservation_number', 'like', '%'.$search.'%')
                    ->orWhere('channel_reference', 'like', '%'.$search.'%')
                    ->orWhereIn('room_id', Room::query()->select('id')->where('number', 'like', '%'.$search.'%'))
                    ->orWhereIn('id', ReservationGuest::query()->select('reservation_id')
                        ->whereIn('guest_id', Guest::query()->select('id')->where(fn ($guests) => $guests->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%'))));
            });
        }

        return $query->orderBy('arrival')->orderBy('reservation_number');
    }
}
