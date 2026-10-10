<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Guests\Models\ReservationGuest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MergeGuests
{
    public function handle(User $actor, Guest $survivor, string $duplicateId): void
    {
        Audit::validationRefusal($actor, $survivor, 'Guest merge refused', function () use ($actor, $survivor, $duplicateId): void {
            DB::transaction(function () use ($actor, $survivor, $duplicateId): void {
                $guests = Guest::query()->where('property_id', $survivor->property_id)->whereKey([$survivor->id, $duplicateId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $duplicate = $guests->get($duplicateId);
                $surviving = $guests->get($survivor->id);
                if ($duplicateId === $survivor->id || ! $duplicate || ! $surviving) {
                    throw ValidationException::withMessages(['duplicate_id' => __('Choose a different guest at this property.')]);
                }
                foreach (ReservationGuest::query()->where('guest_id', $duplicateId)->lockForUpdate()->get() as $link) {
                    ReservationGuest::query()->firstOrCreate(['reservation_id' => $link->getAttribute('reservation_id'), 'guest_id' => $surviving->id]);
                    $link->delete();
                }
                // Move ciphertext without decrypting or serializing identity fields.
                IdentityDocument::query()->where('guest_id', $duplicateId)->update(['guest_id' => $surviving->id]);
                Audit::record($actor, 'merged', $surviving, 'Duplicate guests merged', [
                    'surviving_guest' => $surviving->only(['id', 'name']),
                    'duplicate_guest' => $duplicate->only(['id', 'name']),
                ]);
                $duplicate->delete();
            });
        });
    }
}
