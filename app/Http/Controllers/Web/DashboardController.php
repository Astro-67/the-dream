<?php

namespace App\Http\Controllers\Web;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\DashboardRooms;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Reports\Collections;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\ReservationLists;
use App\Domains\Tenancy\OnboardingChecklist;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, OnboardingChecklist $checklist, ReservationLists $lists): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $property = Tenancy::property();
        $onboarding = $property === null ? null : $checklist->forProperty($property, $actor);
        $date = $property === null ? null : BusinessDate::of($property);
        $period = $request->validate(['period' => ['sometimes', Rule::in(['today', 'this-month', 'this-year'])]])['period'] ?? 'today';

        return Inertia::render('dashboard', [
            'onboarding' => $onboarding,
            'roomBoard' => $date === null || $onboarding !== null || ! $actor->can('viewAny', Reservation::class) ? null : [
                'businessDate' => $date,
                'rooms' => app(DashboardRooms::class)->forDate($date),
                'calendarHref' => route('rate-calendar'),
                'housekeepingHref' => $actor->can('viewAny', RoomStatus::class) ? route('housekeeping.index') : null,
            ],
            'collections' => $property === null || $property->isDemo() || $onboarding !== null || ! $actor->can('view', PropertyKpis::class)
                ? null : app(Collections::class)->card($property, $period),
            // A live property's day at a glance, for members who may see reservations.
            'today' => $date === null || $onboarding !== null || ! $actor->can('viewAny', Reservation::class) ? null : [
                'businessDate' => $date,
                'lists' => collect(['arrivals', 'departures', 'in-house'])->map(fn (string $list) => [
                    'key' => $list,
                    'count' => $lists->query($list, $date, '')->count(),
                    'href' => route('reservations.index', ['list' => $list, 'date' => $date]),
                ])->all(),
            ],
        ]);
    }
}
