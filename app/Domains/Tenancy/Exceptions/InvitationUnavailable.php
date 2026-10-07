<?php

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\Enums\InvitationStatus;
use RuntimeException;

/**
 * The invitation is no longer pending: it was used, revoked or has expired.
 */
final class InvitationUnavailable extends RuntimeException
{
    public function __construct(public readonly InvitationStatus $status)
    {
        parent::__construct($status->refusal() ?? 'This invitation is no longer available.');
    }
}
