<?php

use App\Http\Controllers\Web\FiscalReceipting\VfmsSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent', 'two-factor:owner'])->group(function () {
    Route::put('properties/{property}/vfms', [VfmsSettingsController::class, 'update'])->name('properties.vfms.update');
    Route::put('properties/{property}/vfms/mode', [VfmsSettingsController::class, 'mode'])->name('properties.vfms.mode');
});
