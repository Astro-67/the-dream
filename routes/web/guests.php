<?php
use App\Http\Controllers\Web\Guests\GuestController;
use Illuminate\Support\Facades\Route;
Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('guests', [GuestController::class, 'index'])->name('guests.index');
    Route::get('guests/create', [GuestController::class, 'create'])->name('guests.create');
    Route::post('guests', [GuestController::class, 'store'])->name('guests.store');
    Route::get('guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
    Route::get('guests/{guest}/edit', [GuestController::class, 'edit'])->name('guests.edit');
    Route::put('guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
});
