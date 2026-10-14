<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\CreateOrganization;
use App\Domains\Tenancy\Enums\Country;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
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
            'countries' => array_map(fn (Country $country) => ['value' => $country->value, 'label' => $country->label()], Country::cases()),
            ...self::propertyFormOptions('setup'),
        ]);
    }

    /**
     * @return array{
     *     propertyTypes: list<array{value: string, label: string}>,
     *     currencies: list<array{value: string, label: string}>,
     *     jurisdictionProfiles: list<array{value: string, label: string}>,
     *     timezones: list<string>, defaults: array<string, string>
     * }
     */
    public static function propertyFormOptions(string $mode): array
    {
        return [
            'propertyTypes' => array_map(fn (PropertyType $type) => ['value' => $type->value, 'label' => $type->label()], PropertyType::cases()),
            'currencies' => array_map(fn (Currency $currency) => ['value' => $currency->value, 'label' => $currency->value], Currency::cases()),
            'jurisdictionProfiles' => array_map(fn (JurisdictionProfile $profile) => ['value' => $profile->value, 'label' => $profile->label()], JurisdictionProfile::cases()),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaults' => [
                'timezone' => Property::DEFAULT_TIMEZONE,
                'currency' => Currency::TZS->value,
                ...($mode === 'setup' ? ['country' => Country::Tanzania->value] : []),
            ],
        ];
    }

    public function store(StoreOrganizationRequest $request, CreateOrganization $createOrganization): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $organization = $createOrganization->handle($user, $request->setup());

        $context = Tenancy::defaultContextFor($user);

        if ($context !== null) {
            Tenancy::remember($request->session(), $context);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is set up. Next, work through the checklist below.', ['name' => $organization->name])]);

        return redirect()->route('dashboard');
    }
}
