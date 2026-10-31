<?php

use App\Http\Controllers\Web\Platform\DeclineGoLiveController;
use App\Http\Controllers\Web\Platform\OrganizationController;
use App\Http\Controllers\Web\Platform\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * Platform pages: for platform administrators only, never for organization
 * members (owners included), and only with two-factor authentication on.
 * They sit outside the `tenant` middleware; anything read across
 * organizations goes through the platform context, which logs it.
 */
Route::middleware(['auth', 'verified', 'platform:platform_administrator', 'two-factor'])
    ->prefix('platform')
    ->name('platform.')
    ->group(function () {
        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::post('properties/{property}/decline-go-live', DeclineGoLiveController::class)->name('properties.decline-go-live');

        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::delete('staff/{user}/roles/{role}', [StaffController::class, 'destroy'])->name('staff.destroy');
    });
