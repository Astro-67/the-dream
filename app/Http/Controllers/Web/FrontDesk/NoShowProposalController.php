<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Reservations\Actions\ConfirmNoShow;
use App\Domains\Reservations\Actions\RejectNoShow;
use App\Domains\Reservations\Models\NoShowProposal;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\ResolveNoShowRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class NoShowProposalController extends Controller
{
    public function __invoke(ResolveNoShowRequest $request, NoShowProposal $noShowProposal): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $confirm = $request->validated('decision') === 'confirm';
        $confirm ? app(ConfirmNoShow::class)->handle($user, $noShowProposal) : app(RejectNoShow::class)->handle($user, $noShowProposal);
        $close = BusinessDateClose::query()->whereDate('business_date', $noShowProposal->business_date->toDateString())->firstOrFail();

        return to_route('night-audit.show', $close)->with('toast', ['type' => 'success', 'message' => $confirm ? __('No-show confirmed.') : __('No-show rejected.')]);
    }
}
