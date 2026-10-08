<?php

use App\Http\Controllers\Web\Rates\RatePlanController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('rate-plans', [RatePlanController::class, 'index'])->name('rate-plans.index');
    Route::get('rate-plans/create', [RatePlanController::class, 'create'])->name('rate-plans.create');
    Route::post('rate-plans', [RatePlanController::class, 'store'])->name('rate-plans.store');
});
