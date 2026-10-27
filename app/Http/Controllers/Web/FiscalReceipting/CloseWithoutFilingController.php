<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\CloseWithoutFiling;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\RecoverVfmsFilingRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CloseWithoutFilingController extends Controller
{
    public function __invoke(RecoverVfmsFilingRequest $request, Reservation $reservation, CloseWithoutFiling $close): RedirectResponse
    {
        $close->handle($request->user(), $reservation, $request->string('reason')->value());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Stay closed without filing and listed as a fiscal exception.')]);

        return back();
    }
}
