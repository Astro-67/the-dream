<?php

namespace App\Http\Controllers\Web\Tenancy;

use App\Domains\Guests\Models\IdentityDocument;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Actions\UpdateProperty;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenancy\PropertyRequest;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Tenancy\Tenancy;
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
                'jurisdictionLabel' => $property->jurisdiction_profile->label(),
                'demo' => $property->isDemo(),
                'can' => [
                    'update' => $user->can('update', $property),
                    // A demo property is deleted, never archived.
                    'archive' => ! $property->isDemo() && $user->can('archive', $property),
                    'delete' => $property->isDemo() && $user->can('delete', $property),
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
                    'jurisdictionLabel' => $property->jurisdiction_profile->label(),
                    'timezone' => $property->timezone,
                    'archivedAt' => $property->archived_at?->toIso8601String(),
                    'demo' => $property->isDemo(),
                    'can' => ['restore' => $user->can('restore', $property)],
                ])->values(),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', Property::class);

        return Inertia::render('properties/create', [
            ...$this->formOptions('create'),
            'defaults' => [
                'timezone' => Property::DEFAULT_TIMEZONE,
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
            ...$this->formOptions('edit'),
            'property' => [
                ...$property->only(['star_rating', 'tin', 'z_number', 'vrn']),
                ...$property->settings(),
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
                'housekeeping_inspection_required' => $property->housekeeping_inspection_required,
                'stayover_service_on_close' => $property->stayover_service_on_close,
                'receipt_prefix' => $property->receipt_prefix,
                'close_time' => substr($property->close_time, 0, 5),
                // Zanzibar-only: never sent for a mainland property.
                ...(JurisdictionRules::hasZraHotelCode($property->jurisdiction_profile) ? ['zra_hotel_code' => $property->zra_hotel_code] : []),
                ...(JurisdictionRules::configuresRegistrationFields($property->jurisdiction_profile) ? ['registration_fields' => $property->registration_fields ?? []] : []),
            ],
            'registrationFieldOptions' => IdentityDocument::FIELDS,
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
    private function formOptions(string $mode): array
    {
        unset($mode);

        $options = OrganizationSetupController::propertyFormOptions('property');
        unset($options['defaults']['country']);

        return $options;
    }
}
