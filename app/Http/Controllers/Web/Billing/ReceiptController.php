<?php

namespace App\Http\Controllers\Web\Billing;

use App\Domains\Billing\Models\Receipt;
use App\Domains\Billing\ReceiptView;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ReceiptController extends Controller
{
    public function show(Receipt $receipt): Response
    {
        Gate::authorize('view', $receipt);

        return Inertia::render('receipts/show', ReceiptView::of($receipt));
    }
}
