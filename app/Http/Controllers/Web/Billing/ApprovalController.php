<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\ResolvePostingApproval;
use App\Domains\Billing\Actions\SetApprovalThreshold;
use App\Domains\Billing\Models\ApprovalThreshold;
use App\Domains\Billing\Models\PostingApproval;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\ApprovalThresholdRequest;
use App\Http\Requests\Billing\ResolveApprovalRequest;
use App\Support\Money\Currency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PostingApproval::class);

        return Inertia::render('approvals/index', [
            'approvals' => PostingApproval::query()->where('status', 'pending')->with(['requester:id,name', 'folio.reservation:id,reservation_number'])
                ->orderBy('created_at')->orderBy('id')->paginate(50)->withQueryString()->through(fn (PostingApproval $approval) => [
                    'id' => $approval->id, 'status' => $approval->status, 'folio_id' => $approval->folio_id,
                    'type' => $approval->posting['type'], 'amount' => $approval->posting['amount'], 'currency' => $approval->posting['currency'],
                    'reason' => $approval->posting['reason'], 'requested_by' => $approval->requested_by,
                    'requester' => $approval->requester?->name, 'reservation_number' => $approval->folio->reservation->reservation_number,
                    'canResolve' => $approval->requested_by !== $request->user()?->id,
                ]),
            'thresholds' => ApprovalThreshold::query()->get(['currency', 'amount']),
            'currencies' => array_column(Currency::cases(), 'value'),
        ]);
    }

    public function resolve(ResolveApprovalRequest $request, PostingApproval $approval, ResolvePostingApproval $resolve): RedirectResponse
    {
        $resolve->handle($request->user(), $approval, $request->string('decision')->toString(), $request->filled('reason') ? $request->string('reason')->toString() : null);

        return to_route('approvals.index')->with('toast', ['type' => 'success', 'message' => __('Approval decision recorded.')]);
    }

    public function threshold(ApprovalThresholdRequest $request, SetApprovalThreshold $set): RedirectResponse
    {
        $set->handle($request->user(), $request->string('currency')->toString(), $request->input('amount') === null ? null : $request->integer('amount'));

        return to_route('approvals.index')->with('toast', ['type' => 'success', 'message' => __('Approval threshold saved.')]);
    }
}
