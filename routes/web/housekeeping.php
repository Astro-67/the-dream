<?php

use App\Http\Controllers\Web\Housekeeping\AssignmentController;
use App\Http\Controllers\Web\Housekeeping\BoardController;
use App\Http\Controllers\Web\Housekeeping\RoomStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('housekeeping/my-rooms', [AssignmentController::class, 'own'])->name('housekeeping.my-rooms');
    Route::post('housekeeping/assignments/{assignment}/done', [AssignmentController::class, 'complete'])->name('housekeeping.assignments.complete');
    Route::get('housekeeping/assignments', [AssignmentController::class, 'index'])->name('housekeeping.assignments.index');
    Route::post('housekeeping/assignments', [AssignmentController::class, 'store'])->name('housekeeping.assignments.store');
    Route::delete('housekeeping/assignments/{assignment}', [AssignmentController::class, 'destroy'])->name('housekeeping.assignments.destroy');
    Route::get('housekeeping', [BoardController::class, 'index'])->name('housekeeping.index');
    Route::get('housekeeping/rooms/{room}/history', [RoomStatusController::class, 'history'])->name('housekeeping.history');
    Route::post('housekeeping/rooms/{room}/override-ready', [RoomStatusController::class, 'overrideReady'])->name('housekeeping.override-ready');
    Route::patch('housekeeping/rooms/{room}/status', [RoomStatusController::class, 'update'])->name('housekeeping.status');
});
