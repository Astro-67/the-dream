<?php

namespace App\Domains\Housekeeping\Enums;

enum TransitionSource
{
    case Housekeeping;
    case OccupancyLifecycle;
    case ServicePeriod;
}
