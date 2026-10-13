<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Open balances after departure: folios of checked-out stays whose balance isn't zero, until paid or written off. */
class OpenBalanceController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize('viewOpenBalances', Folio::class);
        $balance = Posting::query()->selectRaw('coalesce(sum(amount), 0)')->whereColumn('postings.folio_id', 'folios.id');
        $folios = Folio::query()
            ->join('reservations', 'reservations.id', '=', 'folios.reservation_id')
            ->where('reservations.status', ReservationStatus::CheckedOut->value)
            ->where($balance, '!=', 0)
            ->select(['folios.id', 'folios.currency', 'reservations.reservation_number', 'reservations.departure'])
            ->selectSub($balance, 'balance')
            ->orderBy('reservations.departure')->orderBy('reservations.reservation_number')
            ->paginate(50)->withQueryString();
        $folios->getCollection()->transform(fn (Folio $folio) => [
            'id' => $folio->id, 'currency' => $folio->currency->value, 'balance' => (int) $folio->getAttribute('balance'),
            'reservation_number' => $folio->getAttribute('reservation_number'),
            'departure' => substr((string) $folio->getAttribute('departure'), 0, 10),
        ]);

        return Inertia::render('billing/open-balances', ['folios' => $folios]);
    }
}
