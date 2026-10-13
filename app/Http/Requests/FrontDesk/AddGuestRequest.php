<?php

namespace App\Http\Requests\FrontDesk;

use App\Http\Requests\Guests\ReservationGuestRequest;

/** Same fields as attaching a guest: a matched `guest_id`, or new guest details. */
class AddGuestRequest extends ReservationGuestRequest {}
