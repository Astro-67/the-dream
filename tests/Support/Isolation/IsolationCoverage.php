<?php

namespace Tests\Support\Isolation;

use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use LogicException;

/**
 * The isolation suite's register of covered routes: every file in
 * tests/Feature/Isolation/Coverage/ returns an array of route key => RouteCoverage.
 *
 * A route's key is its name, or its URI when it has none.
 */
final class IsolationCoverage
{
    public const DIRECTORY = __DIR__.'/../../Feature/Isolation/Coverage';

    /** @var array<string, RouteCoverage>|null */
    private static ?array $declared = null;

    /**
     * @return array<string, RouteCoverage>
     */
    public static function declared(): array
    {
        if (self::$declared !== null) {
            return self::$declared;
        }

        $declared = [];
        $declaredIn = [];

        foreach (glob(self::DIRECTORY.'/*.php') ?: [] as $file) {
            /** @var array<string, RouteCoverage> $entries */
            $entries = require $file;

            foreach ($entries as $key => $coverage) {
                if (isset($declared[$key])) {
                    throw new LogicException("Isolation coverage for [{$key}] is declared twice: in {$declaredIn[$key]} and ".basename($file).'.');
                }

                $declared[$key] = $coverage;
                $declaredIn[$key] = basename($file);
            }
        }

        ksort($declared);

        return self::$declared = $declared;
    }

    /**
     * Keys of the routes the suite attacks (everything not exempt).
     *
     * @return list<string>
     */
    public static function attacked(): array
    {
        return array_keys(array_filter(self::declared(), fn (RouteCoverage $c) => ! $c->isExempt()));
    }

    public static function keyFor(Route $route): string
    {
        return $route->getName() ?? $route->uri();
    }

    /**
     * One message per registered route that has no coverage declared.
     *
     * @return list<string>
     */
    public static function uncovered(RouteCollectionInterface $routes): array
    {
        $declared = self::declared();
        $messages = [];

        foreach ($routes->getRoutes() as $route) {
            $key = self::keyFor($route);

            if (! isset($declared[$key])) {
                $messages[] = sprintf(
                    'Route [%s] (%s %s) has no isolation coverage. Add \'%s\' => RouteCoverage::…() to a file in tests/Feature/Isolation/Coverage/ (see the README there).',
                    $key, implode('|', $route->methods()), $route->uri(), $key,
                );
            }
        }

        return array_values(array_unique($messages));
    }

    /**
     * One message per declared key that matches no registered route.
     *
     * @return list<string>
     */
    public static function stale(RouteCollectionInterface $routes): array
    {
        $registered = array_map(self::keyFor(...), $routes->getRoutes());

        return array_values(array_map(
            fn (string $key) => "Isolation coverage is declared for [{$key}], but no route has that name or URI. Remove or rename the entry.",
            array_diff(array_keys(self::declared()), $registered),
        ));
    }

    public static function route(RouteCollectionInterface $routes, string $key): Route
    {
        foreach ($routes->getRoutes() as $route) {
            if (self::keyFor($route) === $key) {
                return $route;
            }
        }

        throw new LogicException("No registered route has the name or URI [{$key}].");
    }
}
