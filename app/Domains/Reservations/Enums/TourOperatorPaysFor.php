<?php

namespace App\Domains\Reservations\Enums;

enum TourOperatorPaysFor: string
{
    case RoomAndLevy = 'room-and-levy';
    case RoomOnly = 'room-only';
    case RoomLevyAndOtherServices = 'room-levy-and-other-services';
    case RoomAndOtherServices = 'room-and-other-services';
}
