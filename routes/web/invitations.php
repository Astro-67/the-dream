<?php

use App\Http\Controllers\Web\Tenancy\InvitationAcceptanceController;
use App\Http\Controllers\Web\Tenancy\InvitationController;
use Illuminate\Support\Facades\Route;

// Sending and revoking invitations, inside the current organization.
Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::get('invitations/create', [InvitationController::class, 'create'])->name('invitations.create');
    Route::post('invitations', [InvitationController::class, 'store'])->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
});

// The emailed link. Guests see who invited them and sign in or register; accepting needs
// the invited email address, which the link proves, so it doesn't wait for email verification.
Route::get('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');
