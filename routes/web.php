<?php

use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', WelcomeController::class)->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

require __DIR__.'/settings.php';

foreach (glob(__DIR__.'/web/*.php') ?: [] as $routeFile) {
    require $routeFile;
}
