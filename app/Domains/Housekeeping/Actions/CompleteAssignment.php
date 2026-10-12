<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Models\Assignment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class CompleteAssignment
{
    public function handle(User $actor, Assignment $assignment): void
    {
        Audit::validationRefusal($actor, $assignment, 'Housekeeping completion refused', fn () => DB::transaction(function () use ($actor, $assignment) {
            $assignment = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->sole();
            if ($assignment->completed_at !== null) {
                return;
            }

            app(MarkRoomCleaned::class)->handle($actor, $assignment->room);
            $assignment->update(['completed_at' => now()]);
            Audit::record($actor, 'completed', $assignment, 'Housekeeping cleaning completed');
        }));
    }
}
