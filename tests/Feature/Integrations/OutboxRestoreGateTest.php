<?php

use App\Support\Outbox\Outbox;
use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('restore gate command persistently blocks integration writes and delivery', function () {
    $tenant = twoTenants();
    $actor = $tenant->a->owner;

    expect(Artisan::call('outbox:gate', [
        '--actor' => $actor->id,
        '--property' => $tenant->a->property->id,
        '--reason' => 'Restored from an older backup',
    ]))->toBe(0);

    expect(fn () => inTenant($tenant->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'test', (object) ['type' => 'reservation', 'id' => 'reservation-1'], 'changed', ['version' => 1], 'key-1',
    ))))->toThrow(LogicException::class, 'restore gate');

    expect(app('db')->table('outbox_restore_gates')->where('property_id', $tenant->a->property->id)->value('is_closed'))->toBe(1);
    expect(Queue::size('default'))->toBe(0);
});
