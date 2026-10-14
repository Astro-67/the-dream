<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\Reservations\Actions\ConfirmNoShow;
use App\Domains\Reservations\Actions\RejectNoShow;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\ResolveNoShowRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class NoShowProposalController extends Controller
{
    public function confirm(ResolveNoShowRequest $request, NoShowProposal $noShowProposal, ConfirmNoShow $confirm): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $confirm->handle($user, $noShowProposal);

        return back()->with('toast', ['type' => 'success', 'message' => __('No-show confirmed.')]);
    }

    public function reject(ResolveNoShowRequest $request, NoShowProposal $noShowProposal, RejectNoShow $reject): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $reject->handle($user, $noShowProposal);

        return back()->with('toast', ['type' => 'success', 'message' => __('No-show rejected.')]);
    }
}
