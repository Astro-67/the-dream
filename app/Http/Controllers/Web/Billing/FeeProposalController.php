<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\ResolveFee;
use App\Domains\Billing\Models\FeeProposal;
use App\Domains\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ResolveFeeRequest;
use Illuminate\Http\RedirectResponse;

class FeeProposalController extends Controller
{
    public function __invoke(ResolveFeeRequest $request, FeeProposal $feeProposal, ResolveFee $resolve): RedirectResponse
    {
        /** @var 'post'|'waive' $decision */
        $decision = $request->validated('decision');
        $resolve->handle($request->user(), $feeProposal, $decision, $request->validated('reason'));

        return to_route('reservations.show', Reservation::query()->findOrFail($feeProposal->reservation_id))
            ->with('toast', ['type' => 'success', 'message' => $decision === 'post' ? __('Fee posted.') : __('Fee waived.')]);
    }
}
