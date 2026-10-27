<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RevealFiscalIdentity;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\Guests\Models\Guest;
use App\Domains\Guests\Models\ReservationGuest;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Enums\OrganizationPermission;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\RevealFiscalIdentityRequest;
use App\Models\User;
use App\Support\Tenancy\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutReportController extends Controller
{
    public function __invoke(Request $request, Reservation $reservation): Response
    {
        Gate::authorize('view', $reservation);

        return $this->report($request, $reservation, CheckoutReport::of($reservation, Access::allows($request->user(), OrganizationPermission::GuestIdentityView, $reservation)));
    }

    public function reveal(RevealFiscalIdentityRequest $request, Reservation $reservation, RevealFiscalIdentity $reveal): Response
    {
        /** @var User $actor */
        $actor = $request->user();

        return $this->report($request, $reservation, $reveal->handle($actor, $reservation));
    }

    /** @param array<string, mixed> $report */
    private function report(Request $request, Reservation $reservation, array $report): Response
    {
        // Guests still in-house, lead first, as the check-out filed them (#164).
        $ids = ReservationGuest::query()->where('reservation_id', $reservation->id)->whereNull('departed_at')->orderBy('id')->pluck('guest_id');
        $names = Guest::query()->whereIn('id', $ids)->pluck('name', 'id');

        return Inertia::render('fiscal-receipting/checkout-report', [
            ...$report,
            'revealIdentityUrl' => $request->user()?->can('revealFiscalIdentity', $reservation)
                ? route('reservations.fiscal-report.reveal-identity', $reservation) : null,
            'stay' => $reservation->stay(),
            'guests' => $ids->map(fn (string $id) => $names[$id])->values()->all(),
            'property' => Property::query()->findOrFail($reservation->property_id)->only(['name', 'address_line', 'city', 'phone', 'email']),
        ]);
    }
}
