<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RefreshCorrectionDecisions;
use App\Domains\FiscalReceipting\Models\DocumentCorrection;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RefreshCorrectionDecisionsController extends Controller
{
    public function __invoke(Request $request, DocumentCorrection $correction, RefreshCorrectionDecisions $refresh): RedirectResponse
    {
        $refresh->handle($request->user(), $correction);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('ZRA status refresh requested.')]);

        return back();
    }
}
