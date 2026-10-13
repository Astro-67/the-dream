<?php

use App\Http\Controllers\Web\FrontDesk\NightAuditController;
use App\Http\Controllers\Web\FrontDesk\NoShowProposalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('night-audit', [NightAuditController::class, 'index'])->name('night-audit.index');
    Route::post('night-audit', [NightAuditController::class, 'store'])->middleware('property.intent')->name('night-audit.store');
    Route::get('night-audit/{businessDateClose}', [NightAuditController::class, 'show'])->name('night-audit.show');
    Route::post('no-show-proposals/{noShowProposal}/resolve', NoShowProposalController::class)->middleware('property.intent')->name('no-show-proposals.resolve');
});
