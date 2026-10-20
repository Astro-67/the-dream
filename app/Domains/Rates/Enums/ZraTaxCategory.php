<?php

namespace App\Domains\Rates\Enums;

enum ZraTaxCategory: string
{
    case Vat = 'vat';
    case HotelLevy = 'hotel_levy';
    case TourOperator = 'tour_operator';
    case Exempt = 'exempt';
}
