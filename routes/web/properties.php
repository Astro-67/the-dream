<?php

use App\Http\Controllers\Web\Tenancy\ArchivedPropertyController;
use App\Http\Controllers\Web\Tenancy\CurrentPropertyController;
use App\Http\Controllers\Web\Tenancy\PropertyController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    // The property switcher. It carries its target, not a property intent. The organization switcher is in routes/web/organizations.php.
    Route::put('current-property', [CurrentPropertyController::class, 'update'])->name('current-property.update');

    // Forms here carry the property they were opened for (EnsurePropertyIntent).
    // Managing properties is owner-only: owners need two-factor authentication (docs/architecture.md §4.2).
    Route::middleware(['property.intent', 'two-factor:owner'])->group(function () {
        Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
        Route::get('properties/create', [PropertyController::class, 'create'])->name('properties.create');
        Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
        Route::get('properties/{property}/edit', [PropertyController::class, 'edit'])->name('properties.edit');
        Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
        Route::post('properties/{property}/archive', [ArchivedPropertyController::class, 'store'])->name('properties.archive');
        Route::delete('properties/{property}/archive', [ArchivedPropertyController::class, 'destroy'])->name('properties.restore');
    });
});
