<?php

use App\Http\Controllers\Web\Guests\GuestController;
use App\Http\Controllers\Web\Guests\GuestMergeController;
use App\Http\Controllers\Web\Guests\GuestMatchController;
use App\Http\Controllers\Web\Guests\ReservationGuestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('reservations/{reservation:reservation_number}/guests', [ReservationGuestController::class, 'create'])->name('reservations.guests.create');
    Route::post('reservations/{reservation:reservation_number}/guests', [ReservationGuestController::class, 'store'])->name('reservations.guests.store');
    Route::post('guests/matches', GuestMatchController::class)->name('guests.matches');
    Route::get('guests', [GuestController::class, 'index'])->name('guests.index');
    Route::get('guests/create', [GuestController::class, 'create'])->name('guests.create');
    Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
    Route::post('guests/{guest}/merge', GuestMergeController::class)->name('guests.merge');
    Route::get('guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
    Route::get('guests/{guest}/edit', [GuestController::class, 'edit'])->name('guests.edit');
    Route::put('guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
});
