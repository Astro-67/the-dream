<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `two-factor`: the signed-in user must have two-factor
 * authentication enabled (set up and confirmed, using the starter kit's 2FA)
 * before the route runs. Used for platform pages and owner-only actions.
 *
 * Without it, a page visit is sent to the security settings to turn it on;
 * a JSON request is refused with 403. Place it after the middleware that
 * decides whether the user may use the route at all, so people without access
 * are refused rather than asked to set up 2FA.
 */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasEnabledTwoFactorAuthentication()) {
            return $next($request);
        }

        $message = __('Turn on two-factor authentication to continue.');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->route('security.edit');
    }
}
