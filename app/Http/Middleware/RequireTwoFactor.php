<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\Access;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `two-factor`: the signed-in user must have two-factor
 * authentication enabled (set up and confirmed, using the starter kit's 2FA)
 * before the route runs. Used for platform pages.
 *
 * `two-factor:owner` asks it only of owners of the current organization, for
 * owner-only actions (managing properties, members, ownership and organization
 * settings); members in every other role pass. Place it after `tenant`.
 *
 * Without it, a page visit is sent to the security settings to turn it on.
 * They explain why and offer to return to what the user was doing
 * (requirement()): the page they asked for, or for a form post the page the
 * form was on. A JSON request is refused with 403. Place it after the
 * middleware that decides whether the user may use the route at all, so
 * people without access are refused rather than asked to set up 2FA.
 */
class RequireTwoFactor
{
    private const REQUIRED = 'two_factor.required';

    /**
     * Why the user was sent to turn on two-factor authentication, and where to
     * return to afterwards (null if nowhere), until they get back past a gate.
     *
     * @return array{reason: string, returnTo: string|null}|null
     */
    public static function requirement(Request $request): ?array
    {
        $required = $request->session()->get(self::REQUIRED);

        return is_array($required) && is_string($required['reason'] ?? null)
            ? ['reason' => $required['reason'], 'returnTo' => is_string($required['returnTo'] ?? null) ? $required['returnTo'] : null]
            : null;
    }

    public function handle(Request $request, Closure $next, ?string $who = null): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->forget(self::REQUIRED);

            return $next($request);
        }

        if ($user instanceof User && ! $this->appliesTo($user, $who)) {
            return $next($request);
        }

        $message = $who === 'owner'
            ? __('Owners need two-factor authentication to manage properties, members and organization settings. Turn it on to continue.')
            : __('Turn on two-factor authentication to continue.');

        if ($request->expectsJson()) {
            abort(403, $message);
        }

        $request->session()->put(self::REQUIRED, ['reason' => $message, 'returnTo' => $this->whereTheUserWas($request)]);
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        return redirect()->route('security.edit');
    }

    /**
     * A page they asked for can be opened again. A form post can't be replayed,
     * so they go back to the page it came from, if that is one of ours.
     */
    private function whereTheUserWas(Request $request): ?string
    {
        if ($request->isMethod('GET')) {
            return $request->fullUrl();
        }

        $referer = $request->headers->get('referer');
        $root = rtrim($request->root(), '/');

        return is_string($referer) && ($referer === $root || str_starts_with($referer, $root.'/')) ? $referer : null;
    }

    private function appliesTo(User $user, ?string $who): bool
    {
        return match ($who) {
            null => true,
            'owner' => Access::isOwner($user),
            default => throw new InvalidArgumentException("Unknown two-factor audience [{$who}]."),
        };
    }
}
