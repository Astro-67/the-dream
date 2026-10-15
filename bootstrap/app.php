<?php

use App\Domains\Inventory\Exceptions\InventoryRefused;
use App\Http\Middleware\EnsurePlatformRole;
use App\Http\Middleware\EnsurePropertyIntent;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireTwoFactor;
use App\Http\Middleware\SetTenantContext;
use App\Http\Middleware\ThrottleAccountForms;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            ThrottleAccountForms::class,
        ]);

        $middleware->alias([
            'tenant' => SetTenantContext::class,
            'property.intent' => EnsurePropertyIntent::class,
            'platform' => EnsurePlatformRole::class,
            'two-factor' => RequireTwoFactor::class,
        ]);

        // Route model binding must already run inside the tenant context (ADR 0012),
        // and inside the property a form was opened for (tenant, then property.intent, then bindings).
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetTenantContext::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsurePropertyIntent::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['documents']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(fn (InventoryRefused $refused) => back()->withErrors(['inventory' => $refused->getMessage()]));
    })->create();
