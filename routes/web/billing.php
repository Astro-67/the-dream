<?php

use App\Http\Controllers\Web\Billing\ExtraChargeController;
use App\Http\Controllers\Web\Billing\FeeProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::post('folios/{folio}/charges', ExtraChargeController::class)->middleware('property.intent')->name('folios.charges.store');
    Route::post('fee-proposals/{feeProposal}/resolve', FeeProposalController::class)->middleware('property.intent')->name('fee-proposals.resolve');
});
