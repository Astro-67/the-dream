<?php

use App\Http\Controllers\Web\Tenancy\ArchivedPropertyController;
use App\Http\Controllers\Web\Tenancy\CurrentOrganizationController;
use App\Http\Controllers\Web\Tenancy\CurrentPropertyController;
use App\Http\Controllers\Web\Tenancy\DemoPropertyController;
use App\Http\Controllers\Web\Tenancy\GoLiveController;
use App\Http\Controllers\Web\Tenancy\InvitationAcceptanceController;
use App\Http\Controllers\Web\Tenancy\InvitationController;
use App\Http\Controllers\Web\Tenancy\MemberController;
use App\Http\Controllers\Web\Tenancy\OrganizationSetupController;
use App\Http\Controllers\Web\Tenancy\PropertyController;
use Illuminate\Support\Facades\Route;

// Organization setup runs before the user has any tenant.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('organizations/create', [OrganizationSetupController::class, 'create'])->name('organizations.create');
    Route::post('organizations', [OrganizationSetupController::class, 'store'])->name('organizations.store');
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::put('current-organization', [CurrentOrganizationController::class, 'update'])->name('current-organization.update');
    Route::put('current-property', [CurrentPropertyController::class, 'update'])->name('current-property.update');

    Route::middleware(['property.intent', 'two-factor:owner'])->group(function () {
        Route::get('properties', [PropertyController::class, 'index'])->name('properties.index');
        Route::get('properties/create', [PropertyController::class, 'create'])->name('properties.create');
        Route::post('properties', [PropertyController::class, 'store'])->name('properties.store');
        Route::post('properties/demo', [DemoPropertyController::class, 'store'])->name('properties.demo.store');
        Route::delete('properties/{property}', [DemoPropertyController::class, 'destroy'])->name('properties.destroy');
        Route::get('properties/{property}/edit', [PropertyController::class, 'edit'])->name('properties.edit');
        Route::put('properties/{property}', [PropertyController::class, 'update'])->name('properties.update');
        Route::post('properties/{property}/archive', [ArchivedPropertyController::class, 'store'])->name('properties.archive');
        Route::delete('properties/{property}/archive', [ArchivedPropertyController::class, 'destroy'])->name('properties.restore');
        Route::post('properties/{property}/go-live', [GoLiveController::class, 'store'])->name('properties.go-live');
        Route::post('properties/{property}/taxes-reviewed', [GoLiveController::class, 'reviewTaxes'])->name('properties.taxes-reviewed');
    });

    Route::middleware(['two-factor:owner', 'property.intent'])->group(function () {
        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::get('members/{membership}/edit', [MemberController::class, 'edit'])->name('members.edit');
        Route::put('members/{membership}', [MemberController::class, 'update'])->name('members.update');
        Route::delete('members/{membership}', [MemberController::class, 'destroy'])->name('members.destroy');
        Route::post('members/{membership}/ownership', [MemberController::class, 'transferOwnership'])->name('members.transfer-ownership');
    });

    Route::middleware(['two-factor:owner'])->group(function () {
        Route::get('invitations/create', [InvitationController::class, 'create'])->name('invitations.create');
        Route::post('invitations', [InvitationController::class, 'store'])->middleware('property.intent')->name('invitations.store');
        Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->middleware('property.intent')->name('invitations.destroy');
    });
});

Route::get('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'show'])->name('invitations.show');
Route::post('invitations/{organization}/{token}', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept');
