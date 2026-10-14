<?php

use App\Http\Controllers\Web\Onboarding\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('imports', [ImportController::class, 'index'])->name('imports.index');
    Route::get('imports/template', [ImportController::class, 'template'])->name('imports.template');
    Route::post('imports', [ImportController::class, 'store'])->middleware('property.intent')->name('imports.store');
    Route::get('imports/{importJob}', [ImportController::class, 'show'])->name('imports.show');
    Route::post('imports/{importJob}/commit', [ImportController::class, 'commit'])->middleware('property.intent')->name('imports.commit');
});
