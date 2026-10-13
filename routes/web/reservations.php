<?php

use App\Http\Controllers\Web\FrontDesk\CheckInController;
use App\Http\Controllers\Web\FrontDesk\ExtendStayController;
use App\Http\Controllers\Web\Reservations\AmendReservationController;
use App\Http\Controllers\Web\Reservations\AvailabilityController;
use App\Http\Controllers\Web\Reservations\CancelReservationController;
use App\Http\Controllers\Web\Reservations\ReservationController;
use App\Http\Controllers\Web\Reservations\ReservationListController;
use App\Http\Controllers\Web\Reservations\RoomAssignmentController;
use App\Http\Controllers\Web\Reservations\VoidReservationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reservations', ReservationListController::class)->name('reservations.index');
    Route::get('reservations/availability', AvailabilityController::class)->name('reservations.availability');
    Route::get('reservations/create', [ReservationController::class, 'create'])->name('reservations.create');
    Route::get('reservations/{reservation:reservation_number}/amend-preview', [AmendReservationController::class, 'preview'])->name('reservations.amend-preview');
    Route::patch('reservations/{reservation:reservation_number}/amend', AmendReservationController::class)->middleware('property.intent')->name('reservations.amend');
    Route::post('reservations', [ReservationController::class, 'store'])->middleware('property.intent')->name('reservations.store');
    Route::patch('reservations/{reservation:reservation_number}/room-assignment', RoomAssignmentController::class)->middleware('property.intent')->name('reservations.room-assignment');
    Route::post('reservations/{reservation:reservation_number}/void', VoidReservationController::class)->middleware('property.intent')->name('reservations.void');
    Route::post('reservations/{reservation:reservation_number}/cancel', CancelReservationController::class)->middleware('property.intent')->name('reservations.cancel');
    Route::post('reservations/{reservation:reservation_number}/check-in', CheckInController::class)->middleware('property.intent')->name('reservations.check-in');
    Route::post('reservations/{reservation:reservation_number}/extend', ExtendStayController::class)->middleware('property.intent')->name('reservations.extend');
    Route::get('reservations/{reservation:reservation_number}', [ReservationController::class, 'show'])->name('reservations.show');
});
