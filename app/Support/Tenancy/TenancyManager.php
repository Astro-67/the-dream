<?php

namespace App\Support\Tenancy;

use App\Domains\Tenancy\Models\Organization;
use App\Domains\Tenancy\Models\OrganizationMembership;
use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Tenancy\Exceptions\TenantMismatch;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The Tenancy module (ADR 0012). Use it through the Tenancy facade.
 *
 * - within(): the one way in. Web middleware, jobs, console commands, actions
 *   and tests all enter a tenant (or the platform) through it.
 * - current(), organization(), property(), isPlatform(): read the context.
 * - allows(), defaultContextFor(), organizationsFor(), propertiesFor(): decide
 *   which contexts a user may enter, rechecking membership on every call.
 */
final class TenancyManager
{
    /**
     * @var list<array{context: TenantContext, organization: ?Organization, property: ?Property}>
     */
    private array $stack = [];

    /**
     * Run the callback inside the given tenant context, with spatie's team ID set
     * to match. The previous context and team ID are restored afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function within(TenantContext $context, Closure $callback): mixed
    {
        $previousTeamId = getPermissionsTeamId();

        $this->stack[] = ['context' => $context, 'organization' => null, 'property' => null];
        $this->useTeam($context->organizationId);

        try {
            $this->resolveModels();

            if ($context->isPlatform()) {
                $this->logPlatformEntry($context);
            }

            return $callback();
        } finally {
            array_pop($this->stack);
            $this->useTeam($previousTeamId);
        }
    }

    public function current(): ?TenantContext
    {
        return $this->frame()['context'] ?? null;
    }

    public function organization(): ?Organization
    {
        return $this->frame()['organization'] ?? null;
    }

    public function property(): ?Property
    {
        return $this->frame()['property'] ?? null;
    }

    public function isPlatform(): bool
    {
        return $this->current()?->isPlatform() ?? false;
    }

    /**
     * Whether the user may act in this context right now: they are a member of
     * the organization and, when a property is named, may act in that active
     * property. Platform contexts are never granted through membership.
     */
    public function allows(User $user, TenantContext $context): bool
    {
        if ($context->isPlatform()) {
            return false;
        }

        $membership = $this->membership($user, (string) $context->organizationId);

        if ($membership === null) {
            return false;
        }

        if ($context->propertyId === null) {
            return true;
        }

        return $this->permittedProperties($user, $membership)->whereKey($context->propertyId)->exists();
    }

    /**
     * The context a user lands in when none is chosen: their first organization
     * and the first property they may act in there. Null when they have no membership.
     */
    public function defaultContextFor(User $user): ?TenantContext
    {
        $memberships = $this->unscoped(OrganizationMembership::class)
            ->where('user_id', $user->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($memberships as $membership) {
            $property = $this->permittedProperties($user, $membership)->first();

            if ($property !== null) {
                return TenantContext::forProperty($property);
            }
        }

        $first = $memberships->first();

        return $first === null ? null : TenantContext::forOrganization($first->organization_id);
    }

    public function hasMembership(User $user): bool
    {
        return $this->unscoped(OrganizationMembership::class)->where('user_id', $user->getKey())->exists();
    }

    /**
     * Organizations the user currently belongs to, for the organization switcher.
     *
     * @return Collection<int, Organization>
     */
    public function organizationsFor(User $user): Collection
    {
        return $this->unscoped(Organization::class)
            ->whereIn('id', $this->unscoped(OrganizationMembership::class)->where('user_id', $user->getKey())->select('organization_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Active properties of the current organization the user may act in, for the property switcher.
     *
     * @return Collection<int, Property>
     */
    public function propertiesFor(User $user): Collection
    {
        $organizationId = $this->current()?->organizationId;
        $membership = $organizationId === null ? null : $this->membership($user, $organizationId);

        return $membership === null
            ? new Collection
            : $this->permittedProperties($user, $membership)->get();
    }

    /**
     * @return array{context: TenantContext, organization: ?Organization, property: ?Property}|null
     */
    private function frame(): ?array
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    private function resolveModels(): void
    {
        $index = array_key_last($this->stack);
        $context = $this->stack[$index]['context'];

        if ($context->isPlatform()) {
            return;
        }

        $organizationId = (string) $context->organizationId;
        $organization = Organization::query()->find($organizationId)
            ?? throw TenantMismatch::unknownOrganization($organizationId);
        $this->stack[$index]['organization'] = $organization;

        if ($context->propertyId !== null) {
            $property = Property::query()->find($context->propertyId)
                ?? throw TenantMismatch::propertyOutsideOrganization($context->propertyId, $organizationId);
            $property->setRelation('organization', $organization);
            $this->stack[$index]['property'] = $property;
        }
    }

    private function useTeam(int|string|null $teamId): void
    {
        setPermissionsTeamId($teamId);

        // spatie caches role and permission relations per model; drop them so checks use the new team.
        if (Auth::hasUser()) {
            Auth::user()?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function logPlatformEntry(TenantContext $context): void
    {
        // Runs before the callback, so a failed log write means the platform work never starts.
        activity('platform')
            ->event('platform_context_entered')
            ->withProperties(['reason' => $context->platformReason])
            ->log('Entered the platform context');
    }

    private function membership(User $user, string $organizationId): ?OrganizationMembership
    {
        return $this->unscoped(OrganizationMembership::class)
            ->where('user_id', $user->getKey())
            ->where('organization_id', $organizationId)
            ->first();
    }

    /**
     * @return Builder<Property>
     */
    private function permittedProperties(User $user, OrganizationMembership $membership): Builder
    {
        $query = $this->unscoped(Property::class)
            ->where('organization_id', $membership->organization_id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->orderBy('id');

        if (! $membership->all_properties) {
            $query->whereIn('id', $this->unscoped(PropertyMembership::class)
                ->where('user_id', $user->getKey())
                ->where('organization_id', $membership->organization_id)
                ->select('property_id'));
        }

        return $query;
    }

    /**
     * Membership lookups have to cross organizations to answer "where may this
     * user go?". This is the only place outside the platform context that does.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    private function unscoped(string $model): Builder
    {
        return $model::query()->withoutGlobalScope(TenantScope::class);
    }
}
