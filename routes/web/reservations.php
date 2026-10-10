<?php

use App\Http\Controllers\Web\Reservations\AvailabilityController;
use App\Http\Controllers\Web\Reservations\ReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reservations/availability', AvailabilityController::class)->name('reservations.availability');
    Route::get('reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('property.intent')->name('reservations.store');
    Route::get('reservations/{reservation:reservation_number}', [ReservationController::class, 'show'])->name('reservations.show');
});
