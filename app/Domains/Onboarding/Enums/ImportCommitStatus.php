<?php

namespace App\Domains\Onboarding\Enums;

enum ImportCommitStatus: string
{
    case Created = 'created';
    case Duplicate = 'duplicate';
    case Refused = 'refused';
}
