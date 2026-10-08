<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

test('a successor blocked behind an accepted predecessor is released for delivery', function () {
    config(['queue.default' => 'database']);
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $sent = [];

        public function send(array $messages): array
        {
            $this->sent[] = $messages[0]->sequence;

            return [new DeliveryResult('accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    [$first, $second] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];

        return [
            $outbox->record('fake', $subject, 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', $subject, 'changed', ['version' => 2], 'two'),
        ];
    }));

    // The queued successor may run before its predecessor and become blocked.
    expect($first->sequence)->toBe(1)->and($second->sequence)->toBe(2)->and($first->stream_key)->toBe($second->stream_key);
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));
    expect($second->fresh()->status)->toBe('blocked');

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));

    expect($first->fresh()->status)->toBe('delivered')
        ->and($second->fresh()->status)->toBe('pending')
        ->and(Queue::size())->toBe(3); // Two original jobs plus the explicit successor release.

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));
    expect($adapter->sent)->toBe([1, 2])
        ->and($second->fresh()->status)->toBe('delivered');
});
