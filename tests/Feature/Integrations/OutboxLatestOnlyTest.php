<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

function latestOnlyRecord($property, string $key, int $version): OutboxMessage
{
    return inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake-channel', (object) ['type' => 'availability', 'id' => 'property-state'], 'availability.updated',
        ['version' => $version, 'state_key' => $key], (string) $version, $key,
    )));
}

function latestOnlyAdapter(Closure $send): DeliveryAdapter
{
    return new class($send) implements DeliveryAdapter
    {
        public function __construct(private Closure $send) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(
                ordering: 'latest-only',
                coalesceKey: fn (array $payload): string => $payload['state_key'],
            );
        }

        public function send(array $messages): array
        {
            return ($this->send)($messages);
        }
    };
}

test('latest-only policy replaces only pending desired state for the same scoped key', function () {
    [, , $property] = organizationWithOwner();
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(
        fn (array $messages): array => array_map(fn () => new DeliveryResult('accepted'), $messages),
    ));

    $older = latestOnlyRecord($property, 'room-type:1', 1);
    $newer = latestOnlyRecord($property, 'room-type:1', 2);
    $otherKey = latestOnlyRecord($property, 'room-type:2', 3);

    expect($older->fresh()->status)->toBe('superseded')
        ->and($newer->fresh()->status)->toBe('pending')
        ->and($otherKey->fresh()->status)->toBe('pending')
        ->and(inTenant($property, fn () => OutboxMessage::query()->count()))->toBe(3);
});

test('latest-only update waits for immutable in-flight content and dispatches after it completes', function () {
    [, , $property] = organizationWithOwner();
    $latestMessage = null;
    $calls = [];
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(function (array $messages) use (&$latestMessage, &$calls): array {
        $calls[] = $messages[0]->payload['version'];
        if (count($calls) === 1) {
            $latestMessage = latestOnlyRecord($GLOBALS['latestProperty'], 'room-type:1', 2);
        }

        return [new DeliveryResult('accepted')];
    }));
    $GLOBALS['latestProperty'] = $property;
    $first = latestOnlyRecord($property, 'room-type:1', 1);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));

    $firstAttempt = inTenant($property, fn () => OutboxAttempt::query()->sole());
    expect($first->fresh()->status)->toBe('delivered')
        ->and($firstAttempt->request_body)->toContain('"version":1')
        ->and($latestMessage)->not->toBeNull()
        ->and($latestMessage->fresh()->status)->toBe('pending')
        ->and($latestMessage->fresh()->attempt_count)->toBe(0)
        ->and($calls)->toBe([1]);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($latestMessage->id));

    expect($latestMessage->fresh()->status)->toBe('delivered')->and($calls)->toBe([1, 2]);
});
