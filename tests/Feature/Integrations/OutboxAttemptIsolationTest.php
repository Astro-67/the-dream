<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

test('outbox attempt history is scoped to its property', function () {
    [, , $propertyA] = organizationWithOwner(['organization_name' => 'Alpha']);
    [, , $propertyB] = organizationWithOwner(['organization_name' => 'Bravo']);
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): \App\Support\Outbox\DeliveryPolicy
        {
            return new \App\Support\Outbox\DeliveryPolicy();
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('accepted', [], ['identity' => 'private'])];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($propertyA, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['guest_count' => 1], 'key',
    )));
    inTenant($propertyA, fn () => app(DeliveryEngine::class)->deliver($message->id));
    $attempt = inTenant($propertyA, fn () => OutboxAttempt::query()->sole());

    expect(fn () => inTenant($propertyB, fn () => OutboxAttempt::query()->findOrFail($attempt->id)))
        ->toThrow(ModelNotFoundException::class);
});
