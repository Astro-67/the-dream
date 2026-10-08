<?php

use App\Http\Controllers\Web\Inventory\RoomController;
use App\Http\Controllers\Web\Inventory\RoomTypeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('room-types', [RoomTypeController::class, 'index'])->name('room-types.index');
    Route::get('room-types/create', [RoomTypeController::class, 'create'])->name('room-types.create');
    Route::post('room-types', [RoomTypeController::class, 'store'])->name('room-types.store');
    Route::get('room-types/{roomType}/edit', [RoomTypeController::class, 'edit'])->name('room-types.edit');
    Route::put('room-types/{roomType}', [RoomTypeController::class, 'update'])->name('room-types.update');

    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::get('rooms/create', [RoomController::class, 'create'])->name('rooms.create');
    Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::get('rooms/{room}/edit', [RoomController::class, 'edit'])->name('rooms.edit');
    Route::put('rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::put('rooms/{room}/room-type', [RoomController::class, 'move'])->name('rooms.move');
    Route::post('rooms/{room}/deactivate', [RoomController::class, 'deactivate'])->name('rooms.deactivate');
});
