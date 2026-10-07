<?php

use App\Http\Controllers\Web\Tenancy\InvitationAcceptanceController;
use App\Http\Controllers\Web\Tenancy\InvitationController;
use Illuminate\Support\Facades\Route;

// Sending and revoking invitations, inside the current organization. Managing members
// is owner-only: owners need two-factor authentication (docs/architecture.md §4.2).
Route::middleware(['auth', 'verified', 'tenant', 'two-factor:owner'])->group(function () {
    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
    Route::get('invitations/create', [InvitationController::class, 'create'])->name('invitations.create');
    // The invite form carries the property it was opened for, so an invitation opened in one
    // organization can't be sent from another after a switch (EnsurePropertyIntent).
    Route::post('invitations', [InvitationController::class, 'store'])->middleware('property.intent')->name('invitations.store');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->name('invitations.destroy');
});

// The emailed link. Guests see who invited them and sign in or register; accepting needs
// the invited email address, which the link proves, so it doesn't wait for email verification.
Route::get('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');
