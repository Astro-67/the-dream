<?php

namespace App\Domains\Housekeeping\Actions;

use App\Domains\Housekeeping\Models\Assignment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

class UnassignRoom
{
    public function handle(User $actor, Assignment $assignment): void
    {
        DB::transaction(function () use ($actor, $assignment) {
            $assignment = Assignment::query()->whereKey($assignment->id)->lockForUpdate()->sole();
            Audit::record($actor, 'unassigned', $assignment, 'Housekeeping room unassigned');
            $assignment->delete();
        });
    }
}
