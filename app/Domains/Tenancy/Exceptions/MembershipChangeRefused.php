<?php

namespace App\Domains\Tenancy\Exceptions;

use RuntimeException;

/**
 * A change to a member's access breaks the owner rules: an organization always
 * keeps at least one owner, and ownership moves only through a transfer.
 */
final class MembershipChangeRefused extends RuntimeException
{
    public static function lastOwner(): self
    {
        return new self('The organization needs at least one owner. Transfer ownership to another member first.');
    }

    public static function transferToYourself(): self
    {
        return new self('You already own this organization. Choose another member.');
    }

    public static function alreadyAnOwner(string $name): self
    {
        return new self("{$name} is already an owner.");
    }

    public static function notAnOwner(): self
    {
        return new self('Only an owner can transfer ownership.');
    }

    public static function ownershipOnlyByTransfer(): self
    {
        return new self('Ownership moves only through a transfer. Use "Transfer ownership" instead.');
    }
}
