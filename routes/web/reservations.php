<?php

use App\Http\Controllers\Web\Reservations\AvailabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reservations/availability', AvailabilityController::class)->name('reservations.availability');
    Route::get('reservations/create', [\App\Http\Controllers\Web\Reservations\ReservationController::class, 'create'])->name('reservations.create');
    Route::post('reservations', [\App\Http\Controllers\Web\Reservations\ReservationController::class, 'store'])->middleware('property.intent')->name('reservations.store');
    Route::get('reservations/{reservation:reservation_number}', [\App\Http\Controllers\Web\Reservations\ReservationController::class, 'show'])->name('reservations.show');
});
