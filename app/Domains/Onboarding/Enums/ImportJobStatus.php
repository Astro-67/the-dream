<?php

namespace App\Domains\Onboarding\Enums;

enum ImportJobStatus: string
{
    case DryRun = 'dry_run';
    case Committed = 'committed';
}
