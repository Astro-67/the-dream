<?php

namespace App\Domains\Reservations\Enums;

enum MealPlan: string
{
    case RoomOnly = 'room-only';
    case BedAndBreakfast = 'bed-and-breakfast';
    case HalfBoard = 'half-board';
    case FullBoard = 'full-board';
    case AllInclusive = 'all-inclusive';
}
