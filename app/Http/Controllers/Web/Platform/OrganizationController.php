<?php

namespace App\Http\Controllers\Web\Platform;

use App\Domains\Platform\Actions\ListOrganizations;
use App\Domains\Platform\Actions\ShowOrganization;
use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform administrators' list of organizations, for provisioning and support.
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
}
