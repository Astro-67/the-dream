<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Actions\RecordPayment;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\RecordPaymentRequest;
use Illuminate\Http\RedirectResponse;

class PaymentController extends Controller
{
    public function __invoke(RecordPaymentRequest $request, Folio $folio, RecordPayment $record): RedirectResponse
    {
        $payment = $record->handle($request->user(), $folio, $request->payment());

        return to_route('receipts.show', Receipt::query()->where('posting_id', $payment->id)->sole())->with('toast', ['type' => 'success', 'message' => __('Payment recorded.')]);
    }
}
