<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\UpdateProperty;
use App\Domains\Tenancy\Enums\JurisdictionProfile;
use App\Domains\Tenancy\Enums\PropertyType;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\PropertyRequest;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The organization's properties: list, add and edit (ORG-02).
 */
class PropertyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Property::class);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('properties/index', [
            'canCreate' => $user->can('create', Property::class),
            'properties' => Tenancy::propertiesFor($user)->map(fn (Property $property) => [
                'id' => $property->id,
                'name' => $property->name,
                'city' => $property->city,
                'timezone' => $property->timezone,
                'currency' => $property->currency->value,
                'jurisdictionProfile' => $property->jurisdiction_profile->value,
                'can' => [
                    'update' => $user->can('update', $property),
                    'archive' => $user->can('archive', $property),
                ],
            ])->values(),
            // Archived properties stay readable here; they are left out of the switcher and operational lists.
            'archivedProperties' => ! $user->can('viewArchived', Property::class) ? [] : Property::query()
                ->whereNotNull('archived_at')
                ->orderBy('name')
                ->get()
                ->map(fn (Property $property) => [
                    'id' => $property->id,
                    'name' => $property->name,
                    'city' => $property->city,
                    'jurisdictionProfile' => $property->jurisdiction_profile->value,
                    'timezone' => $property->timezone,
                    'archivedAt' => $property->archived_at?->toIso8601String(),
                    'can' => ['restore' => $user->can('restore', $property)],
                ])->values(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Property::class);

        return Inertia::render('properties/create', [
            ...$this->formOptions(),
            'defaults' => [
                'timezone' => 'Africa/Dar_es_Salaam',
                'currency' => Currency::TZS->value,
            ],
        ]);
    }

    public function store(PropertyRequest $request, CreateProperty $createProperty): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $property = $createProperty->handle($user, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }

    public function edit(Property $property): Response
    {
        Gate::authorize('update', $property);

        return Inertia::render('properties/edit', [
            ...$this->formOptions(),
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'property_type' => $property->property_type->value,
                'address_line' => $property->address_line,
                'city' => $property->city,
                'region' => $property->region,
                'postal_code' => $property->postal_code,
                'phone' => $property->phone,
                'email' => $property->email,
                'timezone' => $property->timezone,
                'currency' => $property->currency->value,
                'jurisdiction_profile' => $property->jurisdiction_profile->value,
                // Zanzibar-only: never sent for a mainland property.
                ...($property->isZanzibar() ? ['zra_hotel_code' => $property->zra_hotel_code] : []),
            ],
        ]);
    }

    public function update(PropertyRequest $request, Property $property, UpdateProperty $updateProperty): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $updateProperty->handle($user, $property, $request->details());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name saved.', ['name' => $property->name])]);

        return redirect()->route('properties.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'propertyTypes' => array_map(fn (PropertyType $t) => ['value' => $t->value, 'label' => $t->label()], PropertyType::cases()),
            'currencies' => array_map(fn (Currency $c) => ['value' => $c->value, 'label' => $c->value], Currency::cases()),
            'jurisdictionProfiles' => array_map(fn (JurisdictionProfile $j) => ['value' => $j->value, 'label' => $j->label()], JurisdictionProfile::cases()),
            'timezones' => DateTimeZone::listIdentifiers(),
        ];
    }
}
