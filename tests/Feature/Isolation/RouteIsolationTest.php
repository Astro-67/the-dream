<?php

use Illuminate\Support\Facades\Route;
use Tests\Support\Isolation\IsolationCoverage;

/*
 * The isolation suite (spec #1, release gate for M1): for every route declared
 * in Coverage/*.php, a member of organization Alpha attacks organization Bravo's
 * data and must be refused or see nothing. IsolationCoverageTest makes sure
 * every registered route is declared. How to add a route: Coverage/README.md.
 */
test('Alpha cannot reach Bravo through', function (string $route) {
    IsolationCoverage::declared()[$route]->check($this, twoTenants(), IsolationCoverage::route(Route::getRoutes(), $route));
})->with(fn () => IsolationCoverage::attacked());
