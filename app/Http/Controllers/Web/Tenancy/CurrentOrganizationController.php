<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The organization switcher: makes another organization the member's current
 * one, with the first property they may act in there, after rechecking they
 * still belong to it.
 */
class CurrentOrganizationController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = $request->input('organization_id');

        // One refusal for "not a member (any more)" and "doesn't exist".
        abort_unless(is_string($organizationId) && $organizationId !== '', 403);

        $context = Tenancy::defaultContextFor($user, $organizationId);
        abort_unless($context !== null, 403);

        Tenancy::remember($request->session(), $context);

        // As for a property switch: the previous organization's pages, lists and
        // filters must not come back, so drop the encrypted history and start on the dashboard.
        Inertia::clearHistory();

        return redirect()->route('dashboard');
    }
}
