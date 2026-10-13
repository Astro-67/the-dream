<?php

namespace App\Domains\Reservations\Actions;

use App\Domains\Reservations\Enums\NoShowProposalStatus;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/** A manager rejects night audit's proposal; the reservation stays as it was. */
class RejectNoShow
{
    public function handle(User $actor, NoShowProposal $proposal): NoShowProposal
    {
        return Audit::validationRefusal($actor, $proposal, 'No-show rejection refused', fn () => DB::transaction(
            fn () => NoShowProposal::lockOpen($proposal)->resolve($actor, NoShowProposalStatus::Rejected),
        ));
    }
}
