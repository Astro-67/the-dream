<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\FrontDesk\NightAuditReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\FrontDesk\CloseBusinessDateRequest;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NightAuditController extends Controller
{
    public function index(NightAuditReport $report): Response
    {
        Gate::authorize('viewAny', BusinessDateClose::class);

        return Inertia::render('night-audit/index', [
            ...$report->index(),
            'canClose' => Gate::allows('create', BusinessDateClose::class), 'labels' => $this->labels(),
        ]);
    }

    public function store(CloseBusinessDateRequest $request, CloseBusinessDate $close): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var string $date */
        $date = $request->validated('business_date');
        $result = $close->handle($user, Tenancy::property() ?? abort(404), $date);

        return to_route('night-audit.show', $result)->with('toast', ['type' => 'success', 'message' => __('Business date closed.')]);
    }

    public function show(BusinessDateClose $businessDateClose, NightAuditReport $report): Response
    {
        Gate::authorize('view', $businessDateClose);

        return Inertia::render('night-audit/show', [...$report->summary($businessDateClose), 'labels' => $this->labels()]);
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        return [
            'title' => __('Night audit'), 'businessDate' => __('Business date'),
            'start' => __('Start night audit'), 'help' => __('Post room charges and open the next business date. Departures not checked out stay in house.'),
            'history' => __('Previous closes'), 'empty' => __('No business dates have been closed yet.'),
            'actor' => __('Triggered by'), 'completed' => __('Completed'), 'summary' => __('View summary'),
            'charges' => __('Room charges posted'), 'noCharges' => __('No in-house room charges were posted for this date.'),
            'reservation' => __('Reservation number'), 'price' => __('Nightly price'), 'taxes' => __('Taxes'),
            'amount' => __('Amount'), 'back' => __('Back to night audit'),
            'noShows' => __('No-shows proposed'), 'noShowsHelp' => __('Reservations not arrived by the end of their arrival date. Confirming releases the room and proposes the no-show fee.'),
            'status' => __('Status'), 'confirm' => __('Confirm no-show'), 'reject' => __('Reject'),
            'exceptions' => __('Exceptions for this business date'), 'noExceptions' => __('Nothing was carried from the last close.'),
            'exceptionsCarried' => __('Exceptions carried to the next date'), 'kind' => __('Item'), 'reference' => __('Reference'),
            'no_show' => __('No-show awaiting decision'), 'departure' => __('Departure not checked out'), 'cashier_shift' => __('Cashier shift still open'),
            'proposed' => __('Proposed'), 'confirmed' => __('Confirmed'), 'rejected' => __('Rejected'),
        ];
    }
}
