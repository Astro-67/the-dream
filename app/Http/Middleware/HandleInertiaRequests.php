<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Tenancy\Jurisdiction\JurisdictionRules;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Tenancy\Access;
use App\Support\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // For hiding actions the user can't take. The server still checks every request.
                'permissions' => fn () => $this->permissions($request),
                // For showing platform navigation. Platform routes check the role again.
                'platformRoles' => fn () => $this->platformRoles($request),
            ],
            'tenant' => fn () => $this->tenant($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The current organization and property, and the choices for the switcher.
     * Resolved lazily, so it reads the context the `tenant` middleware entered.
     *
     * @return array<string, mixed>
     */
    private function tenant(Request $request): array
    {
        $user = $request->user();
        $organization = Tenancy::organization();
        $property = Tenancy::property();

        if (! $user instanceof User || $organization === null) {
            return ['organization' => null, 'property' => null, 'organizations' => [], 'properties' => []];
        }

        return [
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'property' => $property === null ? null : $this->propertyProps($property),
            'organizations' => Tenancy::organizationsFor($user)
                ->map(fn (Organization $o) => ['id' => $o->id, 'name' => $o->name])->values(),
            'properties' => Tenancy::propertiesFor($user)
                ->map(fn (Property $p) => ['id' => $p->id, 'name' => $p->name, 'demo' => $p->isDemo()])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function propertyProps(Property $property): array
    {
        $props = [
            'id' => $property->id,
            'name' => $property->name,
            'timezone' => $property->timezone,
            'currency' => $property->currency->value,
            'jurisdictionProfile' => $property->jurisdiction_profile->value,
            'jurisdictionLabel' => $property->jurisdiction_profile->label(),
            'businessDate' => $property->business_date->toDateString(),
            'demo' => $property->isDemo(),
        ];

        // Zanzibar-only fields never reach a mainland property's pages.
        if (JurisdictionRules::hasZraHotelCode($property->jurisdiction_profile)) {
            $props['zraHotelCode'] = $property->zra_hotel_code;
        }

        return $props;
    }

    /**
     * @return list<string>
     */
    private function platformRoles(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User
            ? array_map(fn (PlatformRole $role) => $role->value, $user->platformRoles())
            : [];
    }

    /**
     * The member's permissions in the current property (or organization-wide when there is none).
     *
     * @return list<string>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return $user instanceof User ? Access::permissionsFor($user, Tenancy::property()) : [];
    }
}
