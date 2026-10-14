<?php

use App\Http\Controllers\Web\Reports\PropertyReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reports', PropertyReportController::class)->name('reports.index');
});
