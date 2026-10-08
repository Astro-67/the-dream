<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
 * Every data-changing tenant route carries the property its form was opened
 * for (spec #1, "Switching context"). A new write route without
 * `property.intent` fails here; add it to EXEMPT only when the route changes
 * no property data, with the reason.
 */
const EXEMPT = [
    'current-organization.update' => 'the switcher itself chooses the context',
    'current-property.update' => 'the switcher itself chooses the context',
];

/** @return list<string> */
function routesMissingPropertyIntent(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
        ->filter(fn (Route $route) => in_array('tenant', $route->gatherMiddleware(), true))
        ->reject(fn (Route $route) => in_array('property.intent', $route->gatherMiddleware(), true))
        ->reject(fn (Route $route) => array_key_exists((string) $route->getName(), EXEMPT))
        ->map(fn (Route $route) => $route->getName() ?? $route->uri())
        ->values()
        ->all();
}

test('every data-changing tenant route requires property.intent', function () {
    $missing = routesMissingPropertyIntent();

    expect($missing)->toBeEmpty("Add property.intent (or an EXEMPT entry with a reason) to:\n".implode("\n", $missing));
});

test('a tenant write route without property.intent is reported', function () {
    Router::middleware(['web', 'auth', 'tenant'])->post('_probe/no-intent', fn () => null)->name('_probe.no-intent');

    expect(routesMissingPropertyIntent())->toBe(['_probe.no-intent']);
});
