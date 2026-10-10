<?php

use App\Http\Controllers\Web\Reservations\AvailabilityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reservations/availability', AvailabilityController::class)->name('reservations.availability');
});
