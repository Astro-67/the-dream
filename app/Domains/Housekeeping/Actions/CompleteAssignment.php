<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Enums\HousekeepingStatus;
use App\Domains\Housekeeping\Models\Assignment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class CompleteAssignment
{
    public function handle(User $actor, Assignment $assignment): void
    {
        DB::transaction(function () use ($actor, $assignment) {
            $assignment = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->sole();
            if ($assignment->completed_at !== null) {
                return;
            }

            // #65: switch to MarkRoomCleaned
            app(ChangeRoomStatus::class)->handle($actor, $assignment->room, HousekeepingStatus::Clean, __('Cleaning completed'));
            $assignment->update(['completed_at' => now()]);
            Audit::record($actor, 'completed', $assignment, 'Housekeeping cleaning completed');
        });
    }
}
