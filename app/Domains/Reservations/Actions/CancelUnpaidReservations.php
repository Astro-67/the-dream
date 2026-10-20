<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Support\Facades\DB;

class CancelUnpaidReservations
{
    public function handle(Property $property): void
    {
        $hours = $property->settings()['front_desk_settings']['auto_cancel_unpaid_hours'];
        if ($hours <= 0) {
            return;
        }
        // Channel and imported reservations are paid on the channel's or the old system's terms, never auto-cancelled here.
        Reservation::query()->where('property_id', $property->id)->where('status', ReservationStatus::Confirmed)
            ->whereNull('channel_reference')->where('imported', false)
            ->where('created_at', '<=', now()->subHours($hours))->eachById(function (Reservation $reservation) use ($hours): void {
                DB::transaction(function () use ($reservation, $hours): void {
                    $reservation = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
                    if ($reservation->status !== ReservationStatus::Confirmed || $reservation->created_at->gt(now()->subHours($hours))) {
                        return;
                    }
                    // Payment writers lock the folio; recheck after that lock to avoid cancelling a paid reservation.
                    $folios = Folio::query()->where('reservation_id', $reservation->id)->orderBy('id')->lockForUpdate()->get();
                    $payments = Posting::query()->whereIn('folio_id', $folios->modelKeys())->whereIn('category', ['deposit', 'payment', 'refund'])
                        ->select('currency')->selectRaw('SUM(amount) as received')->groupBy('currency')->get();
                    if (($reservation->channel_collected_amount ?? 0) > 0 || $payments->contains(fn (Posting $payment) => (int) $payment->getAttribute('received') < 0)) {
                        return;
                    }
                    app(CancelReservation::class)->handle(null, $reservation, __('Automatically cancelled: unpaid after :hours hours.', ['hours' => $hours]));
                }, attempts: 3);
            });
    }
}
