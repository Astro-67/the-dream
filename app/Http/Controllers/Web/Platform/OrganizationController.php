<?php

namespace App\Http\Controllers\Web\Platform;

use App\Domains\Platform\Actions\ApproveGoLive;
use App\Domains\Platform\Actions\ListOrganizations;
use App\Domains\Platform\Actions\ShowOrganization;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators' list of organizations, for provisioning and support, and go-live approval.
 */
class OrganizationController extends Controller
{
    public function show(string $organization, ShowOrganization $showOrganization): Response
    {
        return Inertia::render('platform/organizations/show', $showOrganization->handle($organization));
    }

    public function index(ListOrganizations $listOrganizations): Response
    {
        return Inertia::render('platform/organizations/index', [
            'organizations' => $listOrganizations->handle(),
        ]);
    }

    public function approveGoLive(Request $request, string $property, ApproveGoLive $approve): RedirectResponse
    {
        Gate::authorize('approveGoLive', Property::class);

        /** @var User $actor */
        $actor = $request->user();
        $approved = $approve->handle($actor, $property);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':property is live.', ['property' => $approved->name])]);

        return to_route('platform.organizations.show', $approved->organization_id);
    }
}
