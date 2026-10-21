<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\Actions\ClearRoomOccupied;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ends the stay (PMS-05) and hands the room to housekeeping as dirty. Refused
 * while any folio balance isn't zero when the property blocks it, unless a
 * permitted override reason is given. An override leaves an open balance
 * after departure. Automation uses the property's include-unpaid switch instead
 * of a manager override.
 */
class CheckOut
{
    public function handle(?User $actor, Reservation $reservation, ?string $overrideReason = null): Reservation
    {
        return Audit::validationRefusal($actor, $reservation, 'Check-out refused', fn () => DB::transaction(function () use ($actor, $reservation, $overrideReason) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Only in-house reservations can be checked out.')]);
            }
            // Leaving before the departure date must release the unused nights first.
            if ($locked->departure->toDateString() > BusinessDate::forPropertyId($locked->property_id)) {
                throw ValidationException::withMessages(['reservation' => __('This stay departs later. Record an early departure first.')]);
            }
            $open = Folio::query()->where('reservation_id', $locked->id)->orderBy('created_at')->lockForUpdate()->get()
                ->map(fn (Folio $folio) => $folio->balance())->filter(fn ($balance) => $balance->amount !== 0)
                ->map(fn ($balance) => ['amount' => $balance->amount, 'currency' => $balance->currency->value])->values()->all();
            $overrideReason = trim((string) $overrideReason);
            $propertySettings = Property::query()->findOrFail($locked->property_id)->settings();
            $settings = $propertySettings['payment_settings'];
            $blocksUnpaid = $actor === null
                ? ! $propertySettings['front_desk_settings']['automatic_checkout_include_unpaid']
                : $settings['block_checkout_if_unpaid'] && ($overrideReason === '' || ! $settings['manager_override']);
            if ($open !== [] && $blocksUnpaid) {
                throw ValidationException::withMessages(['balance' => __('Settle every folio balance before check-out.')]);
            }
            $details = $open === [] ? [] : ['open_balances' => $open, 'override_reason' => $overrideReason === '' ? null : $overrideReason];

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
