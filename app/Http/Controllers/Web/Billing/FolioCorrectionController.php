<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\OpenFolio;
use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Actions\TransferPosting;
use App\Domains\Billing\Models\Posting;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\OpenFolioRequest;
use App\Http\Requests\Billing\ReversePostingRequest;
use App\Http\Requests\Billing\TransferPostingRequest;
use Illuminate\Http\RedirectResponse;

class FolioCorrectionController extends Controller
{
    public function open(OpenFolioRequest $request, Reservation $reservation, OpenFolio $open): RedirectResponse
    {
        $open->handle($request->user(), $reservation, $request->string('currency')->toString());

        return $this->back($reservation->id, __('Folio opened.'));
    }

    public function reverse(ReversePostingRequest $request, Posting $posting, ReversePosting $reverse): RedirectResponse
    {
        $reverse->handle($request->user(), $posting, $request->string('reason')->toString());

        return $this->back($request->folio()->reservation_id, __('Posting reversed.'));
    }

    public function transfer(TransferPostingRequest $request, Posting $posting, TransferPosting $transfer): RedirectResponse
    {
        $transfer->handle($request->user(), $posting, $request->target(), $request->string('reason')->toString());

        return $this->back($request->folio()->reservation_id, __('Charge moved.'));
    }

    private function back(string $reservationId, string $message): RedirectResponse
    {
        return to_route('reservations.show', Reservation::query()->findOrFail($reservationId))->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
