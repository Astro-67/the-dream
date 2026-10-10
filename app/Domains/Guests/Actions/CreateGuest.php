<?php
namespace App\Domains\Guests\Actions;
use App\Domains\Guests\Models\Guest;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
class CreateGuest {
    /** @param array<string, mixed> $details */
    public function handle(User $actor, array $details): Guest {
        return DB::transaction(function () use ($actor, $details) {
            $guest = Guest::query()->create($details);
            Audit::record($actor, 'created', $guest, 'Guest created', ['attributes' => $guest->only(Guest::FIELDS)]);
            return $guest;
        });
    }
}
