<?php
namespace App\Domains\Guests\Actions;
use App\Domains\Guests\Models\Guest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
class UpdateGuest {
    /** @param array<string, mixed> $details */
    public function handle(User $actor, Guest $guest, array $details): Guest {
        return DB::transaction(function () use ($actor, $guest, $details) {
            $guest->fill($details);
            $changed = array_keys($guest->getDirty());
            $old = $guest->getOriginal();
            $guest->save();
            Audit::record($actor, 'updated', $guest, 'Guest updated', ['old' => array_intersect_key($old, array_flip($changed)), 'attributes' => $guest->only($changed)]);
            return $guest;
        });
    }
}
