<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Booker;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class CreateBooker
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, array $details): Booker
    {
        return DB::transaction(function () use ($actor, $details) {
            $booker = Booker::query()->create($details);
            Audit::record($actor, 'created', $booker, 'Booker created', ['attributes' => $booker->only(Booker::FIELDS)]);

            return $booker;
        });
    }
}
