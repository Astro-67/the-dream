<?php

use App\Http\Controllers\Web\Housekeeping\BoardController;
use App\Http\Controllers\Web\Housekeeping\RoomStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('housekeeping', [BoardController::class, 'index'])->name('housekeeping.index');
    Route::get('housekeeping/rooms/{room}/history', [RoomStatusController::class, 'history'])->name('housekeeping.history');
    Route::patch('housekeeping/rooms/{room}/status', [RoomStatusController::class, 'update'])->name('housekeeping.status');
});
