<?php

namespace App\Http\Controllers\Web;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Housekeeping\DashboardRooms;
use App\Domains\Housekeeping\Models\RoomStatus;
use App\Domains\Reports\Collections;
use App\Domains\Reports\PropertyKpis;
use App\Domains\Reservations\Actions\ListReservations;
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
    public function __invoke(Request $request, OnboardingChecklist $checklist, ReservationLists $lists, ListReservations $reservations): Response
    {
        /** @var User $actor */
        $actor = $request->user();
        $property = Tenancy::property();
        $onboarding = $property === null ? null : $checklist->forProperty($property, $actor);
        $date = $property === null ? null : BusinessDate::of($property);
        $filters = $request->validate([
            'period' => ['sometimes', Rule::in(['today', 'this-month', 'this-year'])],
            'list' => ['sometimes', Rule::in(ReservationLists::FRONT_DESK)],
            'search' => ['nullable', 'string', 'max:255'],
        ]);
        $period = $filters['period'] ?? 'today';
        $list = $filters['list'] ?? 'arrivals';
        $search = $filters['search'] ?? '';
        $canViewReservations = $date !== null && $onboarding === null && $actor->can('viewAny', Reservation::class);

        return Inertia::render('dashboard', [
            'onboarding' => $onboarding,
            'roomBoard' => ! $canViewReservations ? null : [
                'businessDate' => $date,
                'rooms' => app(DashboardRooms::class)->forDate($date),
                'calendarHref' => route('rate-calendar'),
                'housekeepingHref' => $actor->can('viewAny', RoomStatus::class) ? route('housekeeping.index') : null,
            ],
            'collections' => $property === null || $property->isDemo() || $onboarding !== null || ! $actor->can('view', PropertyKpis::class)
                ? null : app(Collections::class)->card($property, $period),
            // A live property's day at a glance, for members who may see reservations.
            'today' => ! $canViewReservations ? null : [
                'businessDate' => $date,
                'lists' => collect(ReservationLists::FRONT_DESK)->map(fn (string $key) => [
                    'key' => $key,
                    'count' => $lists->query($key, $date, '')->count(),
                    'href' => route('reservations.index', [
                        'date' => $date,
                        'list' => match ($key) {
                            'arrivals' => 'today',
                            'in-house' => 'in_house',
                            default => $key,
                        },
                        'search' => $search !== '' ? $search : null,
                    ]),
                ])->all(),
            ],
            'guestQueue' => ! $canViewReservations ? null : [
                'filters' => ['list' => $list, 'search' => $search],
                'period' => $period,
                'reservations' => $reservations->frontDesk($list, $date, $search),
            ],
        ]);
    }
}
