<?php

namespace App\Domains\Onboarding\Enums;

enum ImportRowStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Duplicate = 'duplicate';
}
