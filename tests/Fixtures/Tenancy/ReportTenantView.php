<?php

namespace Tests\Fixtures\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Jobs\TenantAware;
use App\Support\Tenancy\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A tenant-scoped job that writes down what it can see when it runs, so tests
 * can assert on the tenant a worker gave it.
 */
final class ReportTenantView implements ShouldQueue, TenantAware
{
    use Queueable;

    /** @var list<array<string, mixed>> */
    public static array $reports = [];

    public function __construct(public User $user) {}

    public function handle(): void
    {
        self::$reports[] = [
            'organization' => Tenancy::organization()?->name,
            'property' => Tenancy::property()?->name,
            'permission_team' => getPermissionsTeamId(),
            'properties' => Property::query()->orderBy('name')->pluck('name')->all(),
            'property_memberships' => PropertyMembership::query()->pluck('property_id')->unique()->sort()->values()->all(),
            'user_is_owner' => $this->user->hasRole('owner'),
        ];
    }
}
