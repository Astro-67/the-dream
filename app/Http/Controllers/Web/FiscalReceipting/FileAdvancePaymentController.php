<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\Actions\FileAdvancePayment;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\FileAdvancePaymentRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class FileAdvancePaymentController extends Controller
{
    public function __invoke(FileAdvancePaymentRequest $request, Receipt $receipt, FileAdvancePayment $file): RedirectResponse
    {
        $file->handle($request->user(), $receipt, [
            ...$request->validated(), 'is_zra_registered' => $request->boolean('is_zra_registered'),
            'link_existing' => $request->boolean('link_existing'),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $request->boolean('link_existing')
            ? __('Existing advance references linked. No new receipt was issued; ZRA validates the references at check-in.')
            : __('Advance payment queued for ZRA. The fiscal receipt is available only after acceptance.')]);

        return to_route('receipts.show', $receipt);
    }
}
