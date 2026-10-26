<?php

use App\Http\Controllers\Web\FiscalReceipting\CheckoutReportController;
use App\Http\Controllers\Web\FiscalReceipting\RecoverVfmsFilingController;
use App\Http\Controllers\Web\FiscalReceipting\VfmsSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent', 'two-factor:owner'])->group(function () {
    Route::put('properties/{property}/vfms', [VfmsSettingsController::class, 'update'])->name('properties.vfms.update');
    Route::put('properties/{property}/vfms/mode', [VfmsSettingsController::class, 'mode'])->name('properties.vfms.mode');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reservations/{reservation:reservation_number}/fiscal-report', CheckoutReportController::class)->name('reservations.fiscal-report');
});

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::post('reservations/{reservation:reservation_number}/vfms/{message}/refile', RecoverVfmsFilingController::class)->name('reservations.vfms.refile');
    Route::post('reservations/{reservation:reservation_number}/vfms/{message}/retry', RecoverVfmsFilingController::class)->name('reservations.vfms.retry');
});
