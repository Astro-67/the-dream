<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Throttles the guest account forms Fortify registers without a limiter:
 * registration and both password-reset steps. Login and two-factor have their
 * own Fortify limiters. Refusals come back as a form error, not a 429 page.
 */
class ThrottleAccountForms
{
    /** Route name => attempts per minute, per IP. */
    private const LIMITS = [
        'register.store' => 5,
        'password.email' => 5,
        'password.update' => 5,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $name = $request->route()?->getName();

        if ($request->isMethodSafe() || ! is_string($name) || ! array_key_exists($name, self::LIMITS)) {
            return $next($request);
        }

        $key = 'account-forms:'.$name.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::LIMITS[$name])) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        RateLimiter::hit($key);

        return $next($request);
    }
}
