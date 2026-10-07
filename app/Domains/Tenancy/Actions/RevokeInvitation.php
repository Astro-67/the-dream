<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Tenancy\Enums\InvitationStatus;
use App\Domains\Tenancy\Exceptions\InvitationUnavailable;
use App\Domains\Tenancy\Models\Invitation;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Withdraws a pending invitation, so its link can no longer be accepted.
 */
class RevokeInvitation
{
    public function handle(User $actor, Invitation $invitation): Invitation
    {
        return DB::transaction(function () use ($actor, $invitation) {
            /** @var Invitation $invitation */
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($invitation->getKey());

            if ($invitation->status() !== InvitationStatus::Pending) {
                throw new InvitationUnavailable($invitation->status());
            }

            $invitation->update(['revoked_at' => now(), 'revoked_by' => $actor->getKey()]);

            Audit::record($actor, 'revoked', $invitation, 'Invitation revoked', [
                'result' => 'success',
                'attributes' => ['email' => $invitation->email, 'role' => $invitation->role->value],
            ]);

            return $invitation;
        });
    }
}
