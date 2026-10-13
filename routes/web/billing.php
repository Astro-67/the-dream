<?php

use App\Http\Controllers\Web\Billing\CashierShiftController;
use App\Http\Controllers\Web\Billing\ExtraChargeController;
use App\Http\Controllers\Web\Billing\FeeProposalController;
use App\Http\Controllers\Web\Billing\FolioCorrectionController;
use App\Http\Controllers\Web\Billing\PaymentController;
use App\Http\Controllers\Web\Billing\ReceiptController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::post('folios/{folio}/charges', ExtraChargeController::class)->middleware('property.intent')->name('folios.charges.store');
    Route::post('reservations/{reservation:reservation_number}/folios', [FolioCorrectionController::class, 'open'])->middleware('property.intent')->name('reservations.folios.store');
    Route::post('postings/{posting}/reverse', [FolioCorrectionController::class, 'reverse'])->middleware('property.intent')->name('postings.reverse');
    Route::post('postings/{posting}/transfer', [FolioCorrectionController::class, 'transfer'])->middleware('property.intent')->name('postings.transfer');
    Route::post('fee-proposals/{feeProposal}/resolve', FeeProposalController::class)->middleware('property.intent')->name('fee-proposals.resolve');
    Route::post('folios/{folio}/payments', PaymentController::class)->middleware('property.intent')->name('folios.payments.store');
    Route::get('cashier-shifts', [CashierShiftController::class, 'index'])->name('cashier-shifts.index');
    Route::post('cashier-shifts', [CashierShiftController::class, 'store'])->middleware('property.intent')->name('cashier-shifts.store');
    Route::post('cashier-shifts/{cashierShift}/close', [CashierShiftController::class, 'close'])->middleware('property.intent')->name('cashier-shifts.close');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
});
