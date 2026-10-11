<?php

namespace App\Http\Controllers\Web\Guests;

use App\Domains\Guests\Actions\CreateGuest;
use App\Domains\Guests\Actions\UpdateGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\GuestRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class GuestController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Guest::class);

        return Inertia::render('guests/index', [
            'canManage' => $request->user()?->can('create', Guest::class),
            'records' => Guest::query()->orderBy('name')->orderBy('id')->paginate(50)->withQueryString()->through(fn (Guest $guest) => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)]),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', Guest::class);

        return Inertia::render('guests/create', ['canViewIdentity' => Access::allows($request->user(), OrganizationPermission::GuestIdentityView, Tenancy::property())]);
    }

    public function store(GuestRequest $request, CreateGuest $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $guest = $action->handle($actor, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guest added.')]);

        return redirect()->route('guests.show', $guest);
    }

    public function show(Guest $guest, Request $request): Response
    {
        Gate::authorize('view', $guest);

        return Inertia::render('guests/show', ['canMerge' => $request->user()?->can('merge', $guest), 'stays' => Reservation::query()->whereIn('id', ReservationGuest::query()->select('reservation_id')->where('guest_id', $guest->id))->orderByDesc('arrival')->orderByDesc('id')->paginate(50)->withQueryString()->through(fn (Reservation $reservation) => ['reservation_number' => $reservation->reservation_number, 'arrival' => $reservation->arrival->toDateString(), 'departure' => $reservation->departure->toDateString(), 'status' => $reservation->status->value]), 'documents' => $guest->identityFields($request->user()?->can('viewIdentity', $guest) ?? false), 'canViewIdentity' => $request->user()?->can('viewIdentity', $guest), 'record' => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)], 'canManage' => $request->user()?->can('update', $guest)]);
    }

    public function edit(Guest $guest, Request $request): Response
    {
        Gate::authorize('update', $guest);

        return Inertia::render('guests/edit', ['documents' => $guest->identityFields($request->user()?->can('viewIdentity', $guest) ?? false), 'canViewIdentity' => $request->user()?->can('viewIdentity', $guest), 'record' => ['id' => $guest->id, ...$guest->only(Guest::FIELDS)]]);
    }

    public function update(GuestRequest $request, Guest $guest, UpdateGuest $action): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $action->handle($actor, $guest, $request->details());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Guest saved.')]);

        return redirect()->route('guests.show', $guest);
    }
}
