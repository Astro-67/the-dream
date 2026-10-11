<?php

namespace App\Domains\Guests\Actions;

use App\Domains\Guests\Models\Guest;

class ReplaceIdentityDocuments
{
    /** @param list<array<string, mixed>> $documents */
    public function handle(Guest $guest, array $documents): void
    {
        // The guest action owns the transaction and audit; identity values never enter the audit.
        $guest->documents()->delete();
        $guest->documents()->createMany($documents);
    }
}
