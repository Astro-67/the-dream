<?php

use App\Http\Controllers\Web\Subscriptions\PlanController;
use App\Http\Controllers\Web\Subscriptions\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'two-factor:owner'])->group(function () {
    Route::get('subscription', [SubscriptionController::class, 'show'])->name('subscription.show');
});

// Platform pages (see routes/web/platform.php).
Route::middleware(['auth', 'verified', 'platform:platform_administrator', 'two-factor'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
    });
