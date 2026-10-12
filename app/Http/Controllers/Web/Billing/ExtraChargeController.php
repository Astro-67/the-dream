<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\PostExtraCharge;
use App\Domains\Billing\Models\Folio;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\PostExtraChargeRequest;
use Illuminate\Http\RedirectResponse;

class ExtraChargeController extends Controller
{
    public function __invoke(PostExtraChargeRequest $request, Folio $folio, PostExtraCharge $post): RedirectResponse
    {
        $post->handle($request->user(), $folio, $request->charge());

        return to_route('reservations.show', Reservation::query()->findOrFail($folio->reservation_id))->with('toast', ['type' => 'success', 'message' => __('Charge posted.')]);
    }
}
