<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetTenantContext;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The property switcher: makes another property of the current organization
 * the member's current property, after rechecking they may act in it.
 */
class CurrentPropertyController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $organizationId = Tenancy::current()?->organizationId;
        $propertyId = $request->input('property_id');

        // One refusal for "not yours", "not in this organization" and "doesn't exist".
        abort_unless(is_string($organizationId) && is_string($propertyId) && $propertyId !== '', 403);

        $context = TenantContext::fromIds($organizationId, $propertyId);
        abort_unless(Tenancy::allows($user, $context), 403);

        SetTenantContext::remember($request->session(), $context);

        // Pages, lists and filters of the previous property must not come back:
        // drop the encrypted browser history and land on the dashboard, not back on a filtered list.
        Inertia::clearHistory();

        return redirect()->route('dashboard');
    }
}
