<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\VfmsAdapter;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Corrects a booking group's paying institution until ZRA accepts its check-in; re-filing stays a separate step. */
class CorrectPayingInstitution
{
    private const FIELDS = ['paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number'];

    /** Confirmed, or checked in with a refused check-in filing: pending or unknown bodies are built, and VFMS cannot change an accepted one. */
    public static function editable(Reservation $reservation, bool $lock = false): bool
    {
        return $reservation->status === ReservationStatus::Confirmed
            || ($reservation->status === ReservationStatus::CheckedIn && OutboxMessage::query()->where('integration', VfmsAdapter::INTEGRATION)
                ->where('property_id', $reservation->property_id)
                ->where('subject_type', $reservation->getMorphClass())->where('subject_id', $reservation->id)
                ->whereIn('event', ['accommodation', 'advanceAccommodation'])->orderByDesc('sequence')
                ->when($lock, fn ($query) => $query->lockForUpdate())->value('status') === 'rejected');
    }

    /**
     * @param  array<string, mixed>  $details  validated by PayingInstitutionRules
     * @return list<string> reservation numbers of group members left unchanged because they are locked
     */
    public function handle(User $actor, Reservation $reservation, array $details): array
    {
        $after = [...array_fill_keys(self::FIELDS, null), ...array_intersect_key($details, array_flip(self::FIELDS))];

        return Audit::validationRefusal($actor, $reservation, 'Paying institution correction refused', fn () => DB::transaction(function () use ($actor, $reservation, $after) {
            $members = Reservation::query()->where('property_id', $reservation->property_id)
                ->when($reservation->getAttribute('booking_group_id'), fn ($query, $group) => $query->where('booking_group_id', $group), fn ($query) => $query->whereKey($reservation->id))
                ->orderBy('reservation_number')->lockForUpdate()->get();
            if (! self::editable($members->firstOrFail(fn (Reservation $member) => $member->id === $reservation->id), lock: true)) {
                throw ValidationException::withMessages(['paying_institution_type' => __('The paying institution cannot change once ZRA has accepted the check-in, or while its filing is waiting or unknown.')]);
            }
            $unchanged = [];
            foreach ($members as $member) {
                if (! self::editable($member, lock: true)) {
                    $unchanged[] = $member->reservation_number;

                    continue;
                }
                $before = $member->only(self::FIELDS);
                $member->update($after);
                if ($member->only(self::FIELDS) == $before) {
                    continue;
                }
                app(RecordLifecycleEvent::class)->handle($member, 'paying_institution_changed', $actor, ['before' => $before, 'after' => $member->only(self::FIELDS)]);
                Audit::record($actor, 'updated', $member, 'Paying institution corrected', ['old' => $before, 'attributes' => $member->only(self::FIELDS)]);
            }

            return $unchanged;
        }, attempts: 3));
    }
}
