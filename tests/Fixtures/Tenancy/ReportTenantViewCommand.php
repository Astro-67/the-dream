<?php

namespace Tests\Fixtures\Tenancy;

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Support\Console\TenantCommand;
use App\Support\Tenancy\Tenancy;

/**
 * A tenant-scoped command that writes down what it can see when it runs.
 */
final class ReportTenantViewCommand extends TenantCommand
{
    protected $signature = 'fixture:tenant-view';

    protected $description = 'Report what a tenant-scoped command can see';

    /** @var list<array<string, mixed>> */
    public static array $reports = [];

    public function handle(): int
    {
        self::$reports[] = [
            'organization' => Tenancy::organization()?->name,
            'property' => Tenancy::property()?->name,
            'permission_team' => getPermissionsTeamId(),
            'properties' => Property::query()->orderBy('name')->pluck('name')->all(),
            'property_memberships' => PropertyMembership::query()->pluck('property_id')->unique()->sort()->values()->all(),
        ];

        return self::SUCCESS;
    }
}
