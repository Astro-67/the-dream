<?php

use App\Http\Controllers\Web\Rates\RateCalendarController;
use App\Http\Controllers\Web\Rates\RatePlanController;
use App\Http\Controllers\Web\Rates\RatePriceController;
use App\Http\Controllers\Web\Rates\RateRestrictionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('rate-calendar', [RateCalendarController::class, 'index'])->name('rate-calendar');
    Route::put('rate-calendar/{ratePlan}/prices', [RateCalendarController::class, 'prices'])->name('rate-calendar.prices');
    Route::put('rate-calendar/{ratePlan}/restrictions', [RateCalendarController::class, 'restrictions'])->name('rate-calendar.restrictions');
    Route::get('rate-plans', [RatePlanController::class, 'index'])->name('rate-plans.index');
    Route::get('rate-plans/create', [RatePlanController::class, 'create'])->name('rate-plans.create');
    Route::get('rate-plans/{ratePlan}/prices', [RatePriceController::class, 'index'])->name('rate-plans.prices');
    Route::get('rate-plans/{ratePlan}/quote', [RatePriceController::class, 'quote'])->name('rate-plans.quote');
    Route::put('rate-plans/{ratePlan}/prices', [RatePriceController::class, 'update'])->name('rate-plans.prices.update');
    Route::get('rate-plans/{ratePlan}/restrictions', [RateRestrictionController::class, 'index'])->name('rate-plans.restrictions');
    Route::put('rate-plans/{ratePlan}/restrictions', [RateRestrictionController::class, 'update'])->name('rate-plans.restrictions.update');
    Route::post('rate-plans', [RatePlanController::class, 'store'])->name('rate-plans.store');
    Route::post('rate-plans/{ratePlan}/deactivate', [RatePlanController::class, 'deactivate'])->name('rate-plans.deactivate');
});
