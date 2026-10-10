<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Booker;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class UpdateBooker
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, Booker $booker, array $details): Booker
    {
        return DB::transaction(function () use ($actor, $booker, $details) {
            $booker->fill($details);
            $changed = array_keys($booker->getDirty());
            $old = $booker->getOriginal();
            $booker->save();
            Audit::record($actor, 'updated', $booker, 'Booker updated', ['old' => array_intersect_key($old, array_flip($changed)), 'attributes' => $booker->only($changed)]);

            return $booker;
        });
    }
}
