<?php

use App\Http\Controllers\Web\Tenancy\CurrentOrganizationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    // The organization switcher. Like the property switcher (routes/web/properties.php),
    // it carries its target, not a property intent.
    Route::put('current-organization', [CurrentOrganizationController::class, 'update'])->name('current-organization.update');
});
