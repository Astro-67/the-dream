<?php

namespace App\Domains\Reservations;

use App\Domains\Billing\FolioSummary;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

class BookingGroupMembers
{
    /** @return Collection<int, Reservation> */
    public function reservations(Reservation $reservation): Collection
    {
        return Reservation::query()->where('property_id', $reservation->property_id)
            ->when($reservation->getAttribute('booking_group_id') === null,
                fn ($query) => $query->whereKey($reservation->id),
                fn ($query) => $query->where('booking_group_id', $reservation->getAttribute('booking_group_id')))
            ->orderBy('reservation_number')->get();
    }

    /** @return list<array<string, mixed>> */
    public function forReservation(Reservation $reservation): array
    {
        $members = $this->reservations($reservation)->except([$reservation->id])->values();
        $balances = FolioSummary::checkoutBalances($members);
        $date = BusinessDate::current();

        return $members->map(function (Reservation $member) use ($balances, $date) {
            $folios = FolioSummary::folios($member);
            $totals = collect(FolioSummary::totals($member, $folios))->keyBy('currency');

            return [
                ...$member->only(['reservation_number', 'status', 'arrival', 'departure']),
                'can' => [
                    'checkIn' => $member->isEligibleForCheckIn($date) && Gate::allows('checkIn', $member),
                    'checkOut' => $member->status === ReservationStatus::CheckedIn && Gate::allows('checkOut', $member),
                    'overrideBalance' => Gate::allows('checkOutWithBalance', $member),
                ],
                'checkoutBalances' => $balances[$member->id] ?? [],
                'channelCollectedAmount' => $member->getAttribute('channel_collected_amount'),
                'paymentFolios' => $folios->filter(fn (array $item) => Gate::allows('recordPayment', $item['folio']))
                    ->map(fn (array $item) => [
                        'id' => $item['folio']->id, 'currency' => $item['folio']->currency->value,
                        'total' => $totals->get($item['folio']->currency->value),
                    ])->values()->all(),
            ];
        })->all();
    }
}
