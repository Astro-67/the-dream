<?php

namespace App\Http\Controllers\Web;

use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Reservations\ReservationLists;
use App\Domains\Tenancy\OnboardingChecklist;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
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

        return Inertia::render('dashboard', [
            'onboarding' => $onboarding,
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
