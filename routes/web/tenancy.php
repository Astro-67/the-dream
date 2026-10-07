<?php

use App\Http\Controllers\Web\Tenancy\OrganizationSetupController;
use Illuminate\Support\Facades\Route;

// Organization setup runs before the user has any tenant, so it sits outside the `tenant` middleware.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('organizations/create', [OrganizationSetupController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [OrganizationSetupController::class, 'store'])->name('organizations.store');
});
