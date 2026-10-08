<?php

use App\Http\Controllers\Web\Rates\RatePlanController;
use App\Http\Controllers\Web\Rates\RatePriceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('rate-plans', [RatePlanController::class, 'index'])->name('rate-plans.index');
    Route::get('rate-plans/create', [RatePlanController::class, 'create'])->name('rate-plans.create');
    Route::get('rate-plans/{ratePlan}/prices', [RatePriceController::class, 'index'])->name('rate-plans.prices');
    Route::put('rate-plans/{ratePlan}/prices', [RatePriceController::class, 'update'])->name('rate-plans.prices.update');
    Route::post('rate-plans', [RatePlanController::class, 'store'])->name('rate-plans.store');
});
