<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RecoverVfmsFiling;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\RecoverVfmsFilingRequest;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RecoverVfmsFilingController extends Controller
{
    public function __invoke(RecoverVfmsFilingRequest $request, Reservation $reservation, OutboxMessage $message, RecoverVfmsFiling $recover): RedirectResponse
    {
        $recover->handle($request->user(), $reservation, $message, $request->string('reason')->value(), refile: $request->routeIs('reservations.vfms.refile'));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ZRA filing queued for delivery.')]);

        return back();
    }
}
