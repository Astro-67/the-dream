<?php

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Tenancy\Exceptions\TenantMismatch;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Foundation\Testing\WithConsoleEvents;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Tenancy\ReportTenantViewCommand;

/*
 * Command-line tasks enter a tenant the same way jobs and requests do.
 * Console events are on, as outside tests, so the running command is known.
 */
uses(WithConsoleEvents::class);

beforeEach(function () {
    app(Kernel::class)->registerCommand(new ReportTenantViewCommand);
    ReportTenantViewCommand::$reports = [];
});

test('a tenant-scoped command runs within the organization and property it is given', function () {
    [, $organizationA, $lodge] = organizationWithOwner(['organization_name' => 'Org A', 'property_name' => 'Alpha Lodge']);
    $annex = inTenant($organizationA, fn () => Property::factory()->create(['name' => 'Alpha Annex']));
    organizationWithOwner(['organization_name' => 'Org B', 'property_name' => 'Bravo Resort']);

    $staff = User::factory()->create();
    inTenant($lodge, fn () => PropertyMembership::query()->create(['user_id' => $staff->id]));
    inTenant($annex, fn () => PropertyMembership::query()->create(['user_id' => $staff->id]));

    $this->artisan('fixture:tenant-view', ['--organization' => $organizationA->id, '--property' => $annex->id])
        ->assertSuccessful();
    $this->artisan('fixture:tenant-view', ['--organization' => $organizationA->id])
        ->assertSuccessful();

    expect(ReportTenantViewCommand::$reports)->toBe([
        [
            'organization' => 'Org A',
            'property' => 'Alpha Annex',
            'permission_team' => $organizationA->id,
            'properties' => ['Alpha Annex', 'Alpha Lodge'],
            'property_memberships' => [$annex->id],
        ],
        [
            'organization' => 'Org A',
            'property' => null,
            'permission_team' => $organizationA->id,
            'properties' => ['Alpha Annex', 'Alpha Lodge'],
            'property_memberships' => collect([$lodge->id, $annex->id])->sort()->values()->all(),
        ],
    ]);
});

test('a tenant-scoped command refuses to run without an organization', function () {
    organizationWithOwner();

    $this->artisan('fixture:tenant-view')
        ->expectsOutputToContain('--organization')
        ->assertFailed();

    expect(ReportTenantViewCommand::$reports)->toBe([]);
});

test('a tenant-scoped command refuses a property of another organization', function () {
    [, $organizationA] = organizationWithOwner();
    [, , $propertyB] = organizationWithOwner();

    expect(fn () => $this->artisan('fixture:tenant-view', ['--organization' => $organizationA->id, '--property' => $propertyB->id])->run())
        ->toThrow(TenantMismatch::class);

    expect(ReportTenantViewCommand::$reports)->toBe([]);
});

test('a command working across organizations logs its platform entry with the command and the reason', function () {
    organizationWithOwner(['property_name' => 'Alpha Lodge']);
    organizationWithOwner(['property_name' => 'Bravo Resort']);

    app(Kernel::class)->registerCommand(new ClosureCommand('fixture:platform-count', function () {
        $count = Tenancy::within(TenantContext::platform('Monthly property report'), fn () => Property::query()->count());
        $this->line("Properties: {$count}");
    }));

    $this->artisan('fixture:platform-count')->expectsOutput('Properties: 2')->assertSuccessful();

    $entry = Activity::query()->where('log_name', 'platform')->sole();

    expect($entry->causer)->toBeNull()
        ->and($entry->getExtraProperty('reason'))->toBe('Monthly property report')
        ->and($entry->getExtraProperty('command'))->toBe('fixture:platform-count');
});
