<?php

use App\Http\Controllers\Web\Billing\ExtraChargeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::post('folios/{folio}/charges', ExtraChargeController::class)->middleware('property.intent')->name('folios.charges.store');
});
