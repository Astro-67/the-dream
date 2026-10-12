<?php

use App\Http\Controllers\Web\Housekeeping\BoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('housekeeping', [BoardController::class, 'index'])->name('housekeeping.index');
});
