<?php

namespace App\Http\Controllers\Web\Guests;

use App\Domains\Guests\Actions\AttachReservationGuest;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Guests\ReservationGuestRequest;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReservationGuestController extends Controller
{
    public function create(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);
        Gate::authorize('viewAny', Guest::class);

        return Inertia::render('reservations/guests', [
            'reservationNumber' => $reservation->reservation_number,
            'guests' => Guest::query()->whereIn('id', ReservationGuest::query()->where('reservation_id', $reservation->id)->select('guest_id'))->orderBy('id')->paginate(50, ['id', ...Guest::FIELDS])->withQueryString(),
            'canAttach' => $request->user()?->can('attachGuest', $reservation),
            'inHouse' => $reservation->status === ReservationStatus::CheckedIn,
            'canViewIdentity' => Access::allows($request->user(), OrganizationPermission::GuestIdentityView, Tenancy::property()),
            'leadGuestId' => $reservation->leadGuest()?->id,
            'departedGuestIds' => ReservationGuest::query()->where('reservation_id', $reservation->id)->whereNotNull('departed_at')->orderBy('id')->pluck('guest_id'),
            'canRecordDeparture' => $reservation->status === ReservationStatus::CheckedIn && $request->user()?->can('checkOut', $reservation),
        ]);
    }

    public function store(ReservationGuestRequest $request, Reservation $reservation, AttachReservationGuest $action): RedirectResponse
    {
        $action->handle($request->user(), $reservation, $request->validated());

        return to_route('reservations.guests.create', $reservation)->with('toast', ['type' => 'success', 'message' => __('Guest attached.')]);
    }
}
