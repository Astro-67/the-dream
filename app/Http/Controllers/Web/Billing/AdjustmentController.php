<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\RecordAdjustment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RecordAdjustmentRequest;
use Illuminate\Http\RedirectResponse;

class AdjustmentController extends Controller
{
    public function __invoke(RecordAdjustmentRequest $request, Folio $folio, RecordAdjustment $record): RedirectResponse
    {
        $result = $record->handle($request->user(), $folio, $request->routeIs('folios.refunds.store') ? 'refund' : 'write_off', $request->adjustment());

        return to_route('reservations.show', Reservation::query()->findOrFail($folio->reservation_id))
            ->with('toast', ['type' => 'success', 'message' => $result instanceof PostingApproval ? __('Adjustment awaiting approval.') : __('Adjustment recorded.')]);
    }
}
