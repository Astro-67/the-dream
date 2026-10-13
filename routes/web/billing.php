<?php

use App\Http\Controllers\Web\Billing\ExtraChargeController;
use App\Http\Controllers\Web\Billing\PaymentController;
use App\Http\Controllers\Web\Billing\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::post('folios/{folio}/charges', ExtraChargeController::class)->middleware('property.intent')->name('folios.charges.store');
    Route::post('folios/{folio}/payments', PaymentController::class)->middleware('property.intent')->name('folios.payments.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
});
