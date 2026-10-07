<?php

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * A membership can't be created for this person: the invitation isn't theirs,
 * or they already belong to the organization.
 */
final class MembershipRefused extends RuntimeException
{
    public static function wrongEmail(): self
    {
        return new self('This invitation was sent to a different email address. Sign in with the address it was sent to.');
    }

    public static function alreadyAMember(): self
    {
        return new self('You are already a member of this organization.');
    }
}
