<?php

namespace App\Http\Controllers\Web\Reservations;

use App\Domains\Inventory\Models\RoomType;
use App\Domains\Reservations\AvailabilitySearch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reservations\AvailabilityRequest;
use Inertia\Inertia;
use Inertia\Response;

class AvailabilityController extends Controller
{
    public function __invoke(AvailabilityRequest $request, AvailabilitySearch $search): Response
    {
        /** @var array{arrival?: string, departure?: string, adults?: int|string, children?: int|string, room_type_id?: string|null} $stay */
        $stay = $request->validated();

        return Inertia::render('reservations/availability', [
            'stay' => $stay,
            'roomTypes' => RoomType::query()->orderBy('code')->get(['id', 'name']),
            'results' => isset($stay['arrival'], $stay['departure'], $stay['adults'], $stay['children']) ? $search->search($stay) : null,
        ]);
    }
}
