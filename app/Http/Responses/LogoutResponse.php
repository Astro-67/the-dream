<?php

namespace App\Http\Responses;

use Inertia\Inertia;
use Laravel\Fortify\Http\Responses\LogoutResponse as FortifyLogoutResponse;

/**
 * Tenant pages are kept encrypted in browser history; clearing it on logout
 * means the back button can't bring a signed-out user's pages back.
 */
class LogoutResponse extends FortifyLogoutResponse
{
    public function toResponse($request)
    {
        Inertia::clearHistory();

        return parent::toResponse($request);
    }
}
