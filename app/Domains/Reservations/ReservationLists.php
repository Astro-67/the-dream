<?php

namespace App\Domains\Reservations;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Builder;

class ReservationLists
{
    /** @return Builder<Reservation> */
    public function query(string $list, string $date, string $search): Builder
    {
        $query = Reservation::query();
        match ($list) {
            'arrivals' => $query->whereDate('arrival', $date)->whereIn('status', ['confirmed', 'checked_in']),
            'departures' => $query->whereDate('departure', $date)->whereIn('status', ['confirmed', 'checked_in', 'checked_out']),
            'in-house' => $query->where('status', 'checked_in')->whereDate('arrival', '<=', $date)->whereDate('departure', '>', $date),
            default => null,
        };

        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($query) use ($search) {
                $query->where('reservation_number', 'like', '%'.$search.'%')
                    ->orWhere('channel_reference', 'like', '%'.$search.'%')
                    ->orWhereIn('id', ReservationGuest::query()->select('reservation_id')
                        ->whereIn('guest_id', Guest::query()->select('id')->where('name', 'like', '%'.$search.'%')));
            });
        }

        return $query->orderBy('arrival')->orderBy('reservation_number');
    }
}
