<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `property.intent`, for routes behind `tenant` whose forms change data.
 *
 * Every such form carries the property it was opened for in the `_property`
 * field (the <PropertyIntent /> component on the client). The submission then
 * runs in that property, if the member may still act in it, even when they have
 * switched to another property since. Otherwise it is refused (403). It is never
 * saved to the property selected in the meantime.
 *
 * Reads (GET, HEAD, OPTIONS) pass through, so a whole route group can use it.
 * Runs after SetTenantContext and before SubstituteBindings, so records in the
 * URL are looked up in the intended property.
 */
class EnsurePropertyIntent
{
    public const FIELD = '_property';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organizationId = Tenancy::current()?->organizationId;

        if ($request->isMethodSafe() || ! $user instanceof User || $organizationId === null) {
            return $next($request);
        }

        $propertyId = $request->input(self::FIELD);

        if (! is_string($propertyId) || $propertyId === '') {
            throw ValidationException::withMessages([
                self::FIELD => __('This form does not say which property it is for. Reload the page and try again.'),
            ]);
        }

        // Rechecked on every submission: the member may have lost the property since opening the form.
        $intended = TenantContext::fromIds($organizationId, $propertyId);
        abort_unless(Tenancy::allows($user, $intended), 403);

        /** @var Response */
        return Tenancy::within($intended, fn () => $next($request));
    }
}
