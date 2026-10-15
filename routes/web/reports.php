<?php

use App\Http\Controllers\Web\Reports\GroupReportController;
use App\Http\Controllers\Web\Reports\PropertyReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('reports', PropertyReportController::class)->name('reports.index');
    Route::get('reports/group', GroupReportController::class)->name('reports.group');
});
