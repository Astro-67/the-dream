<?php

namespace App\Domains\Tenancy\Actions;

use App\Domains\Subscriptions\Actions\StartTrial;
use App\Domains\Tenancy\Data\OrganizationSetup;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates a person's organization, makes them its owner, starts its trial
 * subscription and creates its first property (REG-02). Idempotent: if the person already belongs to an
 * organization, that organization is returned and nothing is created.
 */
class CreateOrganization
{
    public function __construct(
        private readonly ProvisionOrganizationRoles $provisionRoles,
        private readonly CreateProperty $createProperty,
        private readonly StartTrial $startTrial,
    ) {}

    public function handle(User $owner, OrganizationSetup $setup): Organization
    {
        return DB::transaction(function () use ($owner, $setup) {
            // Serialize concurrent submissions by the same person (double clicks, retries).
            User::query()->whereKey($owner->getKey())->lockForUpdate()->first();

            if (Tenancy::hasMembership($owner)) {
                return Tenancy::organizationsFor($owner)->firstOrFail();
            }

            $organization = Organization::query()->create([
                'name' => $setup->organizationName,
                'country' => $setup->country->value,
                'created_by' => $owner->getKey(),
            ]);

            $property = Tenancy::within(TenantContext::forOrganization($organization), function () use ($owner, $setup) {
                $this->provisionRoles->handle();

                OrganizationMembership::query()->create([
                    'user_id' => $owner->getKey(),
                    'all_properties' => true,
                ]);
                $owner->assignRole(OrganizationRole::Owner->value);
                $this->startTrial->handle($owner);

                return $this->createProperty->handle($owner, $setup->property);
            });

            Tenancy::within(TenantContext::forProperty($property), function () use ($owner, $organization) {
                Audit::record($owner, 'created', $organization, 'Organization created', [
                    'result' => 'success',
                    'attributes' => $organization->only(['name', 'country']),
                ]);
            });

            return $organization;
        });
    }
}
