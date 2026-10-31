<?php

use App\Http\Controllers\Web\Subscriptions\BillingDetailsController;
use App\Http\Controllers\Web\Subscriptions\PaymentSlipController;
use App\Http\Controllers\Web\Subscriptions\PlanController;
use App\Http\Controllers\Web\Subscriptions\SaasInvoiceController;
use App\Http\Controllers\Web\Subscriptions\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'two-factor:owner'])->group(function () {
    Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
    Route::put('subscription/rooms', [SubscriptionController::class, 'updateRooms'])->name('subscription.rooms.update');
    Route::get('subscription/invoices/{invoice}', [SubscriptionController::class, 'invoice'])->name('subscription.invoices.show');
    Route::post('subscription/invoices/{invoice}/slips', [PaymentSlipController::class, 'store'])->name('subscription.invoices.slips.store');
    Route::get('subscription/slips/{slip}', [PaymentSlipController::class, 'show'])->name('subscription.slips.show');
});

// Platform pages (see routes/web/platform.php).
Route::middleware(['auth', 'verified', 'platform:platform_administrator', 'two-factor'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::put('organizations/{organization}/rooms', [SubscriptionController::class, 'platformUpdateRooms'])->name('organizations.rooms.update');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
        Route::get('invoices', [SaasInvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [SaasInvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [SaasInvoiceController::class, 'show'])->name('invoices.show');
        Route::get('slips/{slip}', [PaymentSlipController::class, 'platformShow'])->name('slips.show');
        Route::get('billing-details', [BillingDetailsController::class, 'edit'])->name('billing-details.edit');
        Route::put('billing-details', [BillingDetailsController::class, 'update'])->name('billing-details.update');
        Route::post('invoices/{invoice}/payments', [SaasInvoiceController::class, 'recordPayment'])->name('invoices.payments.store');
    });
