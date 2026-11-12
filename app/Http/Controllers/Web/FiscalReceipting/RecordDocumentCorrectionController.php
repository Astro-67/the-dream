<?php

namespace App\Http\Controllers\Web\FiscalReceipting;

use App\Domains\FiscalReceipting\Actions\RecordDocumentCorrection;
use App\Domains\FiscalReceipting\Models\IssuedDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalReceipting\RecordDocumentCorrectionRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class RecordDocumentCorrectionController extends Controller
{
    public function __invoke(RecordDocumentCorrectionRequest $request, IssuedDocument $document, RecordDocumentCorrection $record): RedirectResponse
    {
        $record->handle($request->user(), $document, $request->string('replacement_id')->value(), $request->string('reason')->value());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Document correction queued for delivery. This is not ZRA approval.')]);

        return back();
    }
}
