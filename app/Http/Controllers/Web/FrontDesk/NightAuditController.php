<?php

namespace App\Http\Controllers\Web\FrontDesk;

use App\Domains\Billing\Models\Posting;
use App\Domains\FrontDesk\Actions\CloseBusinessDate;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\FrontDesk\Models\BusinessDateClose;
use App\Domains\Reservations\Models\NoShowProposal;
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
    public function index(): Response
    {
        Gate::authorize('viewAny', BusinessDateClose::class);
        $closes = BusinessDateClose::query()->with('actor:id,name')->orderByDesc('business_date')
            ->paginate(50)->withQueryString()->through(fn (BusinessDateClose $close) => $this->row($close));

        return Inertia::render('night-audit/index', [
            'closes' => $closes, 'businessDate' => BusinessDate::current(),
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

    public function show(BusinessDateClose $businessDateClose): Response
    {
        Gate::authorize('view', $businessDateClose);
        $charges = Posting::query()->where('property_id', $businessDateClose->property_id)
            ->whereDate('business_date', $businessDateClose->business_date->toDateString())
            ->where('category', 'room')->where('type', 'charge')->with('reservation:id,reservation_number')
            ->orderBy('id')->paginate(50)->withQueryString()->through(fn (Posting $posting) => [
                'id' => $posting->id, 'reservation_number' => $posting->reservation?->reservation_number,
                'price_line_id' => $posting->price_line_id, 'unit_price' => $posting->unit_price,
                'net' => $posting->net, 'amount' => $posting->amount, 'currency' => $posting->currency->value,
                'tax_lines' => $posting->tax_lines,
            ]);

        $noShows = NoShowProposal::query()->whereDate('business_date', $businessDateClose->business_date->toDateString())
            ->with('reservation:id,reservation_number')->orderBy('id')->get();

        return Inertia::render('night-audit/show', [
            'close' => $this->row($businessDateClose), 'charges' => $charges, 'labels' => $this->labels(),
            'noShowsProposed' => $noShows->count(),
            'noShows' => $noShows->map(fn (NoShowProposal $proposal) => [
                'id' => $proposal->id, 'reservation_number' => $proposal->reservation->reservation_number,
                'status' => $proposal->status, 'can_resolve' => $proposal->status === 'proposed' && Gate::allows('resolve', $proposal),
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(BusinessDateClose $close): array
    {
        return [
            'id' => $close->id, 'business_date' => $close->business_date->toDateString(),
            'actor' => $close->actor->name ?? __('Automation'),
            'completed_at' => $close->completed_at?->toIso8601String(),
        ];
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
            'proposed' => __('Proposed'), 'confirmed' => __('Confirmed'), 'rejected' => __('Rejected'),
        ];
    }
}
