<?php

use App\Http\Controllers\Web\Housekeeping\BoardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('housekeeping', [BoardController::class, 'index'])->name('housekeeping.index');
    Route::get('housekeeping/rooms/{room}/history', [\App\Http\Controllers\Web\Housekeeping\RoomStatusController::class, 'history'])->name('housekeeping.history');
    Route::patch('housekeeping/rooms/{room}/status', [\App\Http\Controllers\Web\Housekeeping\RoomStatusController::class, 'update'])->name('housekeeping.status');
});
