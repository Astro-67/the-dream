<?php

namespace App\Domains\Guests\Actions;

use App\Domains\FrontDesk\InHouseDocuments;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class UpdateGuest
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, Guest $guest, array $details): Guest
    {
        return Audit::validationRefusal($actor, $guest, 'Guest update refused', fn () => DB::transaction(function () use ($actor, $guest, $details) {
            $before = $guest->authorityProfile();
            $guest->fill(Arr::only($details, Guest::FIELDS));
            $changed = array_keys($guest->getDirty());
            $old = $guest->getOriginal();
            $guest->save();
            if (array_key_exists('documents', $details)) {
                app(ReplaceIdentityDocuments::class)->handle($guest, $details['documents']);
                $this->ensureDocumentsAvailable($guest);
            }
            Audit::record($actor, 'updated', $guest, 'Guest updated', ['old' => array_intersect_key($old, array_flip($changed)), 'attributes' => $guest->only($changed)]);
            if ($guest->authorityProfile() !== $before) {
                $this->recordProfileChange($actor, $guest, $before['number']);
            }

            return $guest;
        }));
    }

    /** The in-house document guard of check-in, re-run for every stay the guest is still in-house on (ADR 0016). */
    private function ensureDocumentsAvailable(Guest $guest): void
    {
        $ids = ReservationGuest::query()->where('guest_id', $guest->id)->whereNull('departed_at')->pluck('reservation_id');
        $stays = Reservation::query()->whereIn('id', $ids)->where('status', ReservationStatus::CheckedIn)->get();
        foreach ($stays as $stay) {
            InHouseDocuments::ensureAvailable(Property::query()->findOrFail($stay->property_id), new Collection([$guest->unsetRelation('documents')]), $stay, 'documents');
        }
    }

    /** One guest_profile_changed event per in-house stay of the guest; other stays are not reported. */
    private function recordProfileChange(User $actor, Guest $guest, mixed $oldNumber): void
    {
        $ids = ReservationGuest::query()->where('guest_id', $guest->id)->pluck('reservation_id');
        $stays = Reservation::query()->whereIn('id', $ids)->where('status', ReservationStatus::CheckedIn)->orderBy('id')->lockForUpdate()->get();
        foreach ($stays as $stay) {
            // Snapshots are stored in plain JSON; identity numbers stay encrypted as on the document.
            app(RecordLifecycleEvent::class)->handle($stay, 'guest_profile_changed', $actor, ['guest_id' => $guest->id, 'old_document_number' => $oldNumber === null ? null : Crypt::encryptString($oldNumber)]);
        }
    }
}
