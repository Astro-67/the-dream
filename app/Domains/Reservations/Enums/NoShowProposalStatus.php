<?php

namespace App\Domains\Reservations\Enums;

enum NoShowProposalStatus: string
{
    case Proposed = 'proposed';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
