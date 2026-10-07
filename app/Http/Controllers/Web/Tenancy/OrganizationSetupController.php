<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Enums\Country;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetTenantContext;
use App\Http\Requests\Tenancy\StoreOrganizationRequest;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The one form a newly registered person fills in: their organization and its first property.
 */
class OrganizationSetupController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (Tenancy::hasMembership($user)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('organizations/create', [
            'countries' => array_map(fn (Country $c) => ['value' => $c->value, 'label' => $c->label()], Country::cases()),
            'propertyTypes' => array_map(fn (PropertyType $t) => ['value' => $t->value, 'label' => $t->label()], PropertyType::cases()),
            'currencies' => array_map(fn (Currency $c) => ['value' => $c->value, 'label' => $c->value], Currency::cases()),
            'jurisdictionProfiles' => array_map(fn (JurisdictionProfile $j) => ['value' => $j->value, 'label' => $j->label()], JurisdictionProfile::cases()),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaults' => [
                'country' => Country::Tanzania->value,
                'timezone' => 'Africa/Dar_es_Salaam',
                'currency' => Currency::TZS->value,
            ],
        ]);
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $createOrganization->handle($user, $request->setup());

        $context = Tenancy::defaultContextFor($user);

        if ($context !== null) {
            SetTenantContext::remember($request->session(), $context);
        }

        return redirect()->route('dashboard');
    }
}
