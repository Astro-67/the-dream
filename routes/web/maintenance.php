<?php

use App\Http\Controllers\Web\Maintenance\TicketController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('maintenance', [TicketController::class, 'index'])->name('maintenance.index');
    Route::post('maintenance', [TicketController::class, 'store'])->name('maintenance.store');
    Route::get('maintenance/{ticket}', [TicketController::class, 'show'])->name('maintenance.show');
    Route::post('maintenance/{ticket}/assign', [TicketController::class, 'assign'])->name('maintenance.assign');
    Route::post('maintenance/{ticket}/done', [TicketController::class, 'complete'])->name('maintenance.complete');
});
