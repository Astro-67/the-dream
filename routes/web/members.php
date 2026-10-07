<?php

use App\Http\Controllers\Web\Tenancy\MemberController;
use Illuminate\Support\Facades\Route;

// The organization's members. Forms here carry the property they were opened for (EnsurePropertyIntent).
// Managing members is owner-only (MembershipPolicy): owners need two-factor authentication
// (docs/architecture.md §4.2). Like invitations.index, the list is gated too; other roles with
// members.view see it without two-factor authentication.
Route::middleware(['auth', 'verified', 'tenant', 'two-factor:owner', 'property.intent'])->group(function () {
    Route::get('members', [MemberController::class, 'index'])->name('members.index');
    Route::get('members/{membership}/edit', [MemberController::class, 'edit'])->name('members.edit');
    Route::put('members/{membership}', [MemberController::class, 'update'])->name('members.update');
    Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
    Route::post('members/{membership}/ownership', [MemberController::class, 'transferOwnership'])->name('members.transfer-ownership');
});
