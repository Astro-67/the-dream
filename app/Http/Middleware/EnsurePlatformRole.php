<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `platform:<role>[,<role>]`: only platform staff holding one
 * of the given platform roles may use the route; everyone else, organization
 * owners included, gets 403. With no roles listed, any platform role will do.
 *
 * Platform roles never come from organization membership (ADR 0007), so this
 * checks the user's platform role assignments, not spatie's team roles.
 */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $allowed = $roles === [] ? PlatformRole::cases() : array_map(PlatformRole::from(...), $roles);

        abort_unless($user instanceof User && $this->holdsAny($user, $allowed), 403);

        return $next($request);
    }

    /**
     * @param  list<PlatformRole>  $roles
     */
    private function holdsAny(User $user, array $roles): bool
    {
        return $user->platformRoleAssignments()
            ->whereIn('role', array_map(fn (PlatformRole $role) => $role->value, $roles))
            ->exists();
    }
}
