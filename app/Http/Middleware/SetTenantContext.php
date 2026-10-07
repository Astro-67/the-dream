<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `tenant`: runs the rest of the request inside the tenant
 * context stored in the session, after rechecking the user's membership.
 *
 * - `tenant` (operational pages): a user with no membership is sent to organization setup.
 * - `tenant:optional` (user settings): sets the context when there is one, never redirects.
 *
 * Registered ahead of SubstituteBindings, so route model binding is already scoped.
 */
class SetTenantContext
{
    private const ORGANIZATION_KEY = 'tenancy.organization_id';

    private const PROPERTY_KEY = 'tenancy.property_id';

    public function handle(Request $request, Closure $next, string $mode = 'required'): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $context = $this->resolve($request->session(), $user);

        if ($context === null) {
            return $mode === 'optional'
                ? $next($request)
                : redirect()->route('organizations.create');
        }

        self::remember($request->session(), $context);

        // Tenant pages are kept encrypted in browser history, so a switch (Inertia::clearHistory())
        // makes the previous property's pages unrecoverable with the back button.
        Inertia::encryptHistory();

        /** @var Response */
        return Tenancy::within($context, fn () => $next($request));
    }

    /**
     * Make this the user's current context for later requests (after setup or a switch).
     * Callers must have checked it with Tenancy::allows() first.
     */
    public static function remember(Session $session, TenantContext $context): void
    {
        $session->put(self::ORGANIZATION_KEY, $context->organizationId);
        $session->put(self::PROPERTY_KEY, $context->propertyId);
    }

    private function resolve(Session $session, User $user): ?TenantContext
    {
        $organizationId = $session->get(self::ORGANIZATION_KEY);
        $propertyId = $session->get(self::PROPERTY_KEY);

        if (is_string($organizationId) && $organizationId !== '') {
            $stored = TenantContext::fromIds($organizationId, is_string($propertyId) && $propertyId !== '' ? $propertyId : null);

            if (Tenancy::allows($user, $stored)) {
                return $stored;
            }
        }

        return Tenancy::defaultContextFor($user);
    }
}
