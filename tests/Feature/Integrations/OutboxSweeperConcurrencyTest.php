<?php

use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxSweeper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('repeated sweeps do not queue the same pending message more than once', function () {
    [, , $property] = organizationWithOwner();
    config(['queue.default' => 'database']);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));
    DB::table('jobs')->delete();

    expect(app(OutboxSweeper::class)->sweep())->toBe(1)
        ->and(app(OutboxSweeper::class)->sweep())->toBe(0)
        ->and(Queue::size())->toBe(1);
});
