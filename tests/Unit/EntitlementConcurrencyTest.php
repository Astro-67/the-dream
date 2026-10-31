<?php

use App\Domains\Inventory\Actions\CreateRoom;
use App\Domains\Inventory\Actions\CreateRoomType;
use App\Domains\Subscriptions\Models\Plan;
use App\Domains\Subscriptions\Models\Subscription;
use App\Domains\Subscriptions\PlanUsage;
use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Actions\CreateProperty;
use App\Domains\Tenancy\Data\PropertyDetails;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\Support\InventoryForm;
use Tests\Support\ParallelBookings;
use Tests\TestCase;

pest()->extend(TestCase::class)->use(DatabaseMigrations::class);

test('concurrent property additions and restorations cannot take the same remaining plan slot', function (bool $restore) {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real entitlement concurrency requires MySQL.');
    }
    [$owner, $organization] = organizationWithOwner();
    $details = [...organizationSetupPayload(), 'name' => 'Concurrent property'];
    $tasks = array_fill(0, 2, ['action' => 'property', 'details' => $details]);
    if ($restore) {
        $archived = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray($details)));
        inTenant($organization, fn () => app(ArchiveProperty::class)->handle($owner, $archived));
        $tasks[0] = ['action' => 'restore', 'restore_id' => $archived->id];
    }
    Plan::default()->update(['property_limit' => 2]);
    $results = ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $organization->id,
        'actor_id' => $owner->id, 'tasks' => $tasks,
    ], JSON_THROW_ON_ERROR), 'entitlement-worker.php');

    expect(array_column($results, 'id'))->toHaveCount(1)
        ->and(array_column($results, 'refused'))->toHaveCount(1)
        ->and(inTenant($organization, fn () => PlanUsage::current()['properties']))->toBe(2);
})->with([false, true]);

test('concurrent rooms across different properties cannot take the same remaining room slot', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real entitlement concurrency requires MySQL.');
    }
    [$owner, $organization, $property] = organizationWithOwner();
    $second = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    $tasks = [];
    foreach ([$property, $second] as $target) {
        $type = inTenant($target, fn () => app(CreateRoomType::class)->handle($owner, InventoryForm::roomType()));
        $tasks[] = ['action' => 'room', 'property_id' => $target->id, 'details' => InventoryForm::room($type->id)];
    }
    inTenant($organization, fn () => Subscription::current()->update(['rooms' => 1]));
    $results = ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $organization->id,
        'actor_id' => $owner->id, 'tasks' => $tasks,
    ], JSON_THROW_ON_ERROR), 'entitlement-worker.php');

    expect(array_column($results, 'id'))->toHaveCount(1)
        ->and(array_column($results, 'refused'))->toHaveCount(1)
        ->and(inTenant($organization, fn () => PlanUsage::current()['rooms']))->toBe(1);
});

test('property restoration and a concurrent room addition share the room limit', function () {
    if (DB::getDriverName() !== 'mysql') {
        $this->markTestSkipped('Real entitlement concurrency requires MySQL.');
    }
    [$owner, $organization, $property] = organizationWithOwner();
    $archived = inTenant($organization, fn () => app(CreateProperty::class)->handle($owner, PropertyDetails::fromArray(organizationSetupPayload(), 'property_name')));
    inTenant($archived, function () use ($owner) {
        $type = app(CreateRoomType::class)->handle($owner, InventoryForm::roomType());
        app(CreateRoom::class)->handle($owner, InventoryForm::room($type->id));
    });
    inTenant($organization, fn () => app(ArchiveProperty::class)->handle($owner, $archived));
    $type = inTenant($property, fn () => app(CreateRoomType::class)->handle($owner, InventoryForm::roomType()));
    inTenant($organization, fn () => Subscription::current()->update(['rooms' => 1]));
    $results = ParallelBookings::run(json_encode([
        'connection' => config('database.connections.mysql'), 'organization_id' => $organization->id,
        'actor_id' => $owner->id, 'tasks' => [
            ['action' => 'restore', 'restore_id' => $archived->id],
            ['action' => 'room', 'property_id' => $property->id, 'details' => InventoryForm::room($type->id)],
        ],
    ], JSON_THROW_ON_ERROR), 'entitlement-worker.php');

    expect(array_column($results, 'id'))->toHaveCount(1)
        ->and(array_column($results, 'refused'))->toHaveCount(1)
        ->and(inTenant($organization, fn () => PlanUsage::current()['rooms']))->toBe(1);
});
