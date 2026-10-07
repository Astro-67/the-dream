<?php

use App\Domains\Tenancy\Models\Property;
use App\Domains\Tenancy\Models\PropertyMembership;
use App\Models\User;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\Tenancy\CountPropertiesAcrossOrganizations;
use Tests\Fixtures\Tenancy\RenameProperty;
use Tests\Fixtures\Tenancy\ReportTenantView;

/*
 * Seam 2: queued jobs. Jobs go onto the database queue and a real worker
 * (queue:work) runs them, outside the context they were dispatched from.
 */
beforeEach(function () {
    config(['queue.default' => 'database']);
    ReportTenantView::$reports = [];
});

function runQueuedJobs(): void
{
    test()->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0]);
}

test('a tenant-scoped job sees only the organization and property it was dispatched for, with matching permissions', function () {
    [$owner, $organizationA, $lodge] = organizationWithOwner(['organization_name' => 'Org A', 'property_name' => 'Alpha Lodge']);
    $annex = inTenant($organizationA, fn () => Property::factory()->create(['name' => 'Alpha Annex']));
    organizationWithOwner(['organization_name' => 'Org B', 'property_name' => 'Bravo Resort']);

    $staff = User::factory()->create();
    inTenant($lodge, fn () => PropertyMembership::query()->create(['user_id' => $staff->id]));
    inTenant($annex, fn () => PropertyMembership::query()->create(['user_id' => $staff->id]));

    inTenant($lodge, function () use ($owner) {
        ReportTenantView::dispatch($owner);
    });
    runQueuedJobs();

    expect(ReportTenantView::$reports)->toBe([[
        'organization' => 'Org A',
        'property' => 'Alpha Lodge',
        'permission_team' => $organizationA->id,
        'properties' => ['Alpha Annex', 'Alpha Lodge'],
        'property_memberships' => [$lodge->id],
        'user_is_owner' => true,
    ]]);
});

test('jobs from two tenants run back to back on one worker without leaking context or permissions', function () {
    [$ownerA, $organizationA, $lodge] = organizationWithOwner(['organization_name' => 'Org A', 'property_name' => 'Alpha Lodge']);
    [$ownerB, $organizationB] = organizationWithOwner(['organization_name' => 'Org B', 'property_name' => 'Bravo Resort']);
    inTenant($organizationB, fn () => Property::factory()->create(['name' => 'Bravo Beach']));

    inTenant($lodge, function () use ($ownerA) {
        ReportTenantView::dispatch($ownerA);
    });
    inTenant($organizationB, function () use ($ownerA, $ownerB) {
        ReportTenantView::dispatch($ownerB);
        ReportTenantView::dispatch($ownerA);
    });
    runQueuedJobs();

    expect(ReportTenantView::$reports)->toHaveCount(3)
        ->and(ReportTenantView::$reports[0])->toMatchArray([
            'organization' => 'Org A',
            'property' => 'Alpha Lodge',
            'permission_team' => $organizationA->id,
            'properties' => ['Alpha Lodge'],
            'user_is_owner' => true,
        ])
        ->and(ReportTenantView::$reports[1])->toMatchArray([
            'organization' => 'Org B',
            'property' => null,
            'permission_team' => $organizationB->id,
            'properties' => ['Bravo Beach', 'Bravo Resort'],
            'user_is_owner' => true,
        ])
        ->and(ReportTenantView::$reports[2])->toMatchArray([
            'organization' => 'Org B',
            'permission_team' => $organizationB->id,
            'user_is_owner' => false,
        ])
        ->and(Tenancy::current())->toBeNull()
        ->and(getPermissionsTeamId())->toBeNull();
});

test('a job handed the current property, with its organization loaded, runs in that tenant', function () {
    [, , $lodge] = organizationWithOwner(['property_name' => 'Alpha Lodge']);

    inTenant($lodge, function () {
        RenameProperty::dispatch(Tenancy::property(), 'Alpha Lodge & Spa');
    });
    runQueuedJobs();

    expect(app('queue.failer')->all())->toBe([])
        ->and(inTenant($lodge, fn () => $lodge->fresh()?->name))->toBe('Alpha Lodge & Spa');
});

test('a job dispatched for one tenant cannot change records of another tenant it was handed', function () {
    [, , $lodge] = organizationWithOwner(['property_name' => 'Alpha Lodge']);
    [, $organizationB, $resort] = organizationWithOwner(['property_name' => 'Bravo Resort']);

    inTenant($lodge, function () use ($lodge, $resort) {
        RenameProperty::dispatch($lodge, 'Alpha Lodge & Spa');
        RenameProperty::dispatch($resort, 'Hijacked');
    });
    runQueuedJobs();

    $failed = app('queue.failer')->all();

    expect(inTenant($lodge, fn () => $lodge->fresh()?->name))->toBe('Alpha Lodge & Spa')
        ->and(inTenant($organizationB, fn () => $resort->fresh()?->name))->toBe('Bravo Resort')
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toContain(ModelNotFoundException::class);
});

test('a tenant-scoped job dispatched without a tenant fails with a clear error instead of running', function (Closure $dispatchOutsideTenant) {
    [$owner] = organizationWithOwner();

    $dispatchOutsideTenant($owner);
    test()->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0, '--tries' => 3]);

    $failed = app('queue.failer')->all();

    expect(ReportTenantView::$reports)->toBe([])
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->exception)->toContain('Tenant-scoped job ['.ReportTenantView::class.'] was dispatched without a tenant context')
        ->and(Queue::size())->toBe(0);
})->with([
    'outside any context' => function (User $owner) {
        ReportTenantView::dispatch($owner);
    },
    'from the platform context' => function (User $owner) {
        Tenancy::within(TenantContext::platform('Dispatching tenant work'), function () use ($owner) {
            ReportTenantView::dispatch($owner);
        });
    },
]);

test('other queued work dispatched inside a tenant runs in that tenant too', function () {
    [, , $lodge] = organizationWithOwner(['organization_name' => 'Org A', 'property_name' => 'Alpha Lodge']);
    organizationWithOwner(['organization_name' => 'Org B', 'property_name' => 'Bravo Resort']);

    inTenant($lodge, function () {
        dispatch(function () {
            ReportTenantView::$reports[] = [
                'organization' => Tenancy::organization()?->name,
                'properties' => Property::query()->pluck('name')->all(),
            ];
        });
    });
    runQueuedJobs();

    expect(ReportTenantView::$reports)->toBe([['organization' => 'Org A', 'properties' => ['Alpha Lodge']]]);
});

test('queued work dispatched from the platform context runs in the platform context, and is logged again', function () {
    organizationWithOwner(['property_name' => 'Alpha Lodge']);
    organizationWithOwner(['property_name' => 'Bravo Resort']);

    Tenancy::within(TenantContext::platform('Rebuild reports'), function () {
        dispatch(function () {
            ReportTenantView::$reports[] = ['properties' => Property::query()->count()];
        });
    });
    runQueuedJobs();

    expect(ReportTenantView::$reports)->toBe([['properties' => 2]])
        ->and(Activity::query()->where('log_name', 'platform')->get()->map->getExtraProperty('reason')->all())
        ->toBe(['Rebuild reports', 'Rebuild reports']);
});

test('a job working across organizations does so in the platform context, and the entry is logged with the job and the reason', function () {
    organizationWithOwner(['property_name' => 'Alpha Lodge']);
    organizationWithOwner(['property_name' => 'Bravo Resort']);

    CountPropertiesAcrossOrganizations::dispatch();
    runQueuedJobs();

    $entry = Activity::query()->where('log_name', 'platform')->sole();

    expect(CountPropertiesAcrossOrganizations::$count)->toBe(2)
        ->and($entry->causer)->toBeNull()
        ->and($entry->getExtraProperty('reason'))->toBe('Nightly property count')
        ->and($entry->getExtraProperty('job'))->toBe(CountPropertiesAcrossOrganizations::class);
});
