<?php

use App\Http\Controllers\Web\Tenancy\MemberController;
use Illuminate\Support\Facades\Route;

// The organization's members. Forms here carry the property they were opened for (EnsurePropertyIntent).
Route::middleware(['auth', 'verified', 'tenant', 'property.intent'])->group(function () {
    Route::get('members', [MemberController::class, 'index'])->name('members.index');

    // Owner-only: changing access, removing members and transferring ownership.
    Route::middleware([])->group(function () {
        Route::get('members/{membership}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('members/{membership}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('members/{membership}/ownership', [MemberController::class, 'transferOwnership'])->name('members.transfer-ownership');
    });
});
