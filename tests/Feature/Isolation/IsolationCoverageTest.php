<?php

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\Isolation\IsolationCoverage;
use Tests\Support\Isolation\RouteCoverage;
use Tests\Support\TwoTenants;

test('every registered route has isolation coverage', function () {
    $uncovered = IsolationCoverage::uncovered(Route::getRoutes());

    expect($uncovered)->toBeEmpty(implode("\n", $uncovered));
});

test('every coverage entry names a registered route', function () {
    $stale = IsolationCoverage::stale(Route::getRoutes());

    expect($stale)->toBeEmpty(implode("\n", $stale));
});

test('a new route without isolation coverage is reported by name', function () {
    Route::middleware(['web', 'auth', 'tenant'])
        ->get('_probe/invoices/{invoice}/export', fn () => 'nothing')
        ->name('probe.invoices.export');
    Route::post('_probe/unnamed', fn () => 'nothing');

    $uncovered = implode("\n", IsolationCoverage::uncovered(Route::getRoutes()));

    expect($uncovered)
        ->toContain('probe.invoices.export')
        ->toContain('_probe/unnamed');
});

test('a route that shows another tenant\'s data fails its isolation check', function () {
    $tenants = twoTenants();
    $route = Route::middleware(['web', 'auth', 'tenant'])
        ->get('_probe/leaky', fn () => "Welcome to {$tenants->b->property->name}");

    expect(fn () => RouteCoverage::visit()->check($this, $tenants, $route))
        ->toThrow(AssertionFailedError::class, "Route [_probe/leaky] leaked Bravo's data");
});

test('an attempt that returns another tenant\'s data fails its isolation check', function () {
    $tenants = twoTenants();
    $route = Route::middleware(['web', 'auth', 'tenant'])
        ->get('_probe/export', fn () => response('ok')->header('Content-Disposition', "attachment; filename=\"{$tenants->b->organization->name}.csv\""))
        ->name('probe.export');

    $coverage = RouteCoverage::attempt(fn (TwoTenants $t) => $this->actingAs($t->a->owner)->get('/_probe/export'));

    expect(fn () => $coverage->check($this, $tenants, $route))
        ->toThrow(AssertionFailedError::class, "Route [probe.export] leaked Bravo's data");
});
