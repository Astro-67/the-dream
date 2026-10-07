<?php

namespace App\Domains\Tenancy\Enums;

enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    /**
     * Why an invitation in this status can't be accepted, in words the invitee can act on.
     */
    public function refusal(): ?string
    {
        return match ($this) {
            self::Pending => null,
            self::Accepted => 'This invitation has already been used. Sign in to reach the organization, or ask for a new invitation.',
            self::Revoked => 'This invitation was withdrawn. Ask the person who invited you to send a new one.',
            self::Expired => 'This invitation has expired. Ask the person who invited you to send a new one.',
        };
    }
}
