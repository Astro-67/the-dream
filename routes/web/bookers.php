<?php

use App\Http\Controllers\Web\Guests\BookerController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('bookers', [BookerController::class, 'index'])->name('bookers.index');
    Route::get('bookers/create', [BookerController::class, 'create'])->name('bookers.create');
    Route::post('bookers', [BookerController::class, 'store'])->name('bookers.store');
    Route::get('bookers/{booker}', [BookerController::class, 'show'])->name('bookers.show');
    Route::get('bookers/{booker}/edit', [BookerController::class, 'edit'])->name('bookers.edit');
    Route::put('bookers/{booker}', [BookerController::class, 'update'])->name('bookers.update');
});
