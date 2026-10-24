<?php

namespace App\Domains\FrontDesk\Actions;

use App\Domains\FrontDesk\InHouseDocuments;
use App\Domains\FrontDesk\Registration;
use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registers another staying guest on an in-house reservation, under the same
 * registration rules as check-in. `guest_id` links a matched guest staff chose;
 * otherwise a new guest is created from the details.
 */
class AddGuest
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, Reservation $reservation, array $details): Guest
    {
        return Audit::validationRefusal($actor, $reservation, 'Add guest refused', fn () => DB::transaction(function () use ($actor, $reservation, $details) {
            $locked = Reservation::query()->whereKey($reservation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== ReservationStatus::CheckedIn) {
                throw ValidationException::withMessages(['reservation' => __('Guests can be added only to checked-in reservations.')]);
            }
            $guest = ! empty($details['guest_id']) ? Guest::query()->whereKey($details['guest_id'])->firstOrFail() : app(CreateGuest::class)->handle($actor, $details);
            if (ReservationGuest::query()->where(['reservation_id' => $locked->id, 'guest_id' => $guest->id])->exists()) {
                throw ValidationException::withMessages(['guest_id' => __(':guest is already staying on this reservation.', ['guest' => $guest->name])]);
            }
            $property = Property::query()->findOrFail($locked->property_id);
            $guests = new Collection([$guest]);
            Registration::ensure($property, $guests);
            InHouseDocuments::ensureAvailable($property, $guests, $locked);

            ReservationGuest::query()->create(['reservation_id' => $locked->id, 'guest_id' => $guest->id]);
            app(RecordLifecycleEvent::class)->handle($locked, 'guest_added', $actor, ['guest_id' => $guest->id]);
            Audit::record($actor, 'guest_added', $locked, 'Guest added in house', ['guest_id' => $guest->id]);

            return $guest;
        }, attempts: 3));
    }
}
