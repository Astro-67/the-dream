<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends the stay (PMS-05) and hands the room to housekeeping as dirty. Refused
 * while any folio balance isn't zero, unless an override reason is given; the
 * caller checks the override permission. An override leaves an open balance
 * after departure.
 */
class CheckOut
{
    public function handle(User $actor, Reservation $reservation, ?string $overrideReason = null): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Check-out refused', fn () => DB::transaction(function () use ($actor, $reservation, $overrideReason) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only in-house reservations can be checked out.')]);
            }
            $open = Folio::query()->where('reservation_id', $locked->id)->orderBy('created_at')->lockForUpdate()->get()
                ->map(fn (Folio $folio) => $folio->balance())->filter(fn ($balance) => $balance->amount !== 0)
                ->map(fn ($balance) => ['amount' => $balance->amount, 'currency' => $balance->currency->value])->values()->all();
            $overrideReason = trim((string) $overrideReason);
            if ($open !== [] && $overrideReason === '') {
                throw ValidationException::withMessages(['balance' => __('Settle every folio balance before check-out.')]);
            }
            $details = $open === [] ? [] : ['open_balances' => $open, 'override_reason' => $overrideReason];

            $locked->update(['status' => ReservationStatus::CheckedOut]);
            if ($locked->room_id !== null) {
                app(ClearRoomOccupied::class)->handle($actor, Room::query()->findOrFail($locked->room_id), 'Check-out '.$locked->reservation_number);
            }
            app(RecordLifecycleEvent::class)->handle($locked, 'checked_out', $actor, $details);
            Audit::record($actor, 'checked_out', $locked, $open === [] ? 'Reservation checked out' : 'Reservation checked out with an open balance', $details);

            return $locked;
        }, attempts: 3));
    }
}
