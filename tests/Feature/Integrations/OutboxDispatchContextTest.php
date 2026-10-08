<?php

use App\Support\Outbox\Outbox;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

test('after-commit dispatch captures recording tenancy when outer transaction commits outside tenancy', function () {
    config(['queue.default' => 'database']);
    [, , $property] = organizationWithOwner();
    DB::commit();
    DB::beginTransaction();
    inTenant($property, function () {
        app(Outbox::class)->record(
            'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
            'changed', ['version' => 1], 'stable-key',
        );
    });

    DB::commit();

    $job = DB::table('jobs')->latest('id')->first();
    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['tenancy']['organization_id'])->toBe($property->organization_id)
        ->and($payload['tenancy']['property_id'])->toBe($property->id)
        ->and(Tenancy::current())->toBeNull();
});
