<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Guest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CreateGuest
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, array $details): Guest
    {
        return DB::transaction(function () use ($actor, $details) {
            $guest = Guest::query()->create(Arr::only($details, Guest::FIELDS));
            if (array_key_exists('documents', $details)) {
                app(ReplaceIdentityDocuments::class)->handle($guest, $details['documents']);
            }
            Audit::record($actor, 'created', $guest, 'Guest created', ['attributes' => $guest->only(Guest::FIELDS)]);

            return $guest;
        });
    }
}
