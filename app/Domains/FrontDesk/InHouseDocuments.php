<?php

namespace App\Domains\FrontDesk;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Inventory\Models\Room;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class InHouseDocuments
{
    /**
     * Refuses on $field, the form input the refusal is shown on.
     *
     * @param  Collection<int, Guest>  $guests
     */
    public static function ensureAvailable(Property $property, Collection $guests, Reservation $reservation, string $field = 'guests'): void
    {
        if (! JurisdictionRules::usesVfms($property)) {
            return;
        }

        Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
        $hashes = $guests->loadMissing('documents')->flatMap(fn (Guest $guest) => $guest->documents)->pluck('number_hash')->filter()->all();
        $conflict = IdentityDocument::query()
            ->join('reservation_guests', 'reservation_guests.guest_id', '=', 'identity_documents.guest_id')
            ->join('reservations', 'reservations.id', '=', 'reservation_guests.reservation_id')
            ->where('identity_documents.property_id', $property->id)
            ->where('reservations.property_id', $property->id)
            ->where('reservations.status', ReservationStatus::CheckedIn->value)
            ->where('reservations.id', '!=', $reservation->id)
            // A guest who has left (Guest departure) is checked out at VFMS.
            ->whereNull('reservation_guests.departed_at')
            ->whereIn('identity_documents.number_hash', $hashes)
            ->select('reservations.room_id')
            ->lockForUpdate()
            ->first();

        if ($conflict !== null) {
            $number = Room::query()->whereKey($conflict->getAttribute('room_id'))->value('number');
            throw ValidationException::withMessages([$field => __('A guest with this document is already in-house in room :room.', ['room' => $number])]);
        }
    }
}
