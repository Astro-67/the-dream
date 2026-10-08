<?php

use App\Http\Controllers\Web\Inventory\RoomTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('room-types', [RoomTypeController::class, 'index'])->name('room-types.index');
    Route::get('room-types/create', [RoomTypeController::class, 'create'])->name('room-types.create');
    Route::post('room-types', [RoomTypeController::class, 'store'])->name('room-types.store');
    Route::get('room-types/{roomType}/edit', [RoomTypeController::class, 'edit'])->name('room-types.edit');
    Route::put('room-types/{roomType}', [RoomTypeController::class, 'update'])->name('room-types.update');
});
