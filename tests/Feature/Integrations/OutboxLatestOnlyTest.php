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

function latestOnlyRecord($property, string $key, int $version, string $subject = 'property-state'): OutboxMessage
{
    return inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake-channel', (object) ['type' => 'availability', 'id' => $subject], 'availability.updated',
        ['version' => $version, 'state_key' => $key], (string) $version,
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
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(function (array $messages) use (&$latestMessage, &$calls, $property): array {
        $calls[] = $messages[0]->payload['version'];
        if (count($calls) === 1) {
            $latestMessage = latestOnlyRecord($property, 'room-type:1', 2);
        }

        return [new DeliveryResult('accepted')];
    }));
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

test('unknown latest-only outcome holds its key but not unrelated keys', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(function (array $messages) use (&$calls): array {
        $version = $messages[0]->payload['version'];
        $calls[] = $version;

        return [new DeliveryResult($version === 1 ? 'unknown' : 'accepted')];
    }));
    $uncertain = latestOnlyRecord($property, 'room-type:1', 1, 'room-type-1');
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($uncertain->id));
    $waiting = latestOnlyRecord($property, 'room-type:1', 2, 'room-type-1');
    $independent = latestOnlyRecord($property, 'room-type:2', 3, 'room-type-2');

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($waiting->id));
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($independent->id));

    expect($uncertain->fresh()->status)->toBe('unknown')
        ->and($waiting->fresh()->status)->toBe('pending')
        ->and($independent->fresh()->status)->toBe('delivered')
        ->and($calls)->toBe([1, 3]);
});

test('confirmed rejection does not hold the next latest-only update', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(function (array $messages) use (&$calls): array {
        $version = $messages[0]->payload['version'];
        $calls[] = $version;

        return [new DeliveryResult($version === 1 ? 'rejected' : 'accepted')];
    }));
    $rejected = latestOnlyRecord($property, 'room-type:1', 1, 'room-type-1');
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($rejected->id));
    $newer = latestOnlyRecord($property, 'room-type:1', 2, 'room-type-1');

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($newer->id));

    expect($rejected->fresh()->status)->toBe('rejected')
        ->and($newer->fresh()->status)->toBe('delivered')
        ->and($calls)->toBe([1, 2]);
});

test('latest-only coalesce keys are isolated by organization and property', function () {
    [, , $propertyA] = organizationWithOwner(['organization_name' => 'Alpha']);
    [, , $propertyB] = organizationWithOwner(['organization_name' => 'Bravo']);
    app(DeliveryAdapters::class)->register('fake-channel', latestOnlyAdapter(
        fn (array $messages): array => array_map(fn () => new DeliveryResult('accepted'), $messages),
    ));

    $alphaOlder = latestOnlyRecord($propertyA, 'room-type:1', 1);
    $alphaNewer = latestOnlyRecord($propertyA, 'room-type:1', 2);
    $bravo = latestOnlyRecord($propertyB, 'room-type:1', 3);

    expect($alphaOlder->fresh()->status)->toBe('superseded')
        ->and($alphaNewer->fresh()->status)->toBe('pending')
        ->and($bravo->fresh()->status)->toBe('pending')
        ->and(inTenant($propertyB, fn () => OutboxMessage::query()->find($alphaOlder->id)))->toBeNull();
});

test('latest-only policy requires an explicit callable key', function () {
    expect(fn () => new DeliveryPolicy(ordering: 'latest-only'))->toThrow(InvalidArgumentException::class);
});

test('latest-only key hashing scopes identical keys by organization, property, and integration', function () {
    $policy = new DeliveryPolicy(ordering: 'latest-only', coalesceKey: fn (array $payload): string => $payload['state_key']);

    expect($policy->keyHash('org-a', 'prop-a', 'channel-a', 'rates'))
        ->not->toBe($policy->keyHash('org-b', 'prop-a', 'channel-a', 'rates'))
        ->and($policy->keyHash('org-a', 'prop-a', 'channel-a', 'rates'))
        ->not->toBe($policy->keyHash('org-a', 'prop-b', 'channel-a', 'rates'))
        ->and($policy->keyHash('org-a', 'prop-a', 'channel-a', 'rates'))
        ->not->toBe($policy->keyHash('org-a', 'prop-a', 'channel-b', 'rates'));
});
