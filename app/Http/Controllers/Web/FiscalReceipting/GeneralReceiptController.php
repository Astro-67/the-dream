<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\IssueGeneralReceipt;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\IssueGeneralReceiptRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GeneralReceiptController extends Controller
{
    public function __invoke(IssueGeneralReceiptRequest $request, Reservation $reservation, IssueGeneralReceipt $issue): RedirectResponse
    {
        $issue->forReservation($request->user(), $reservation);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ZRA receipt sent.')]);

        return back();
    }
}
