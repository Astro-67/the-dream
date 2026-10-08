<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

function registerConcurrencyAdapter(Closure $send): void
{
    app(DeliveryAdapters::class)->register('latest-channel', new class($send) implements DeliveryAdapter
    {
        public function __construct(private Closure $send) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(ordering: 'latest-only', coalesceKey: fn (array $payload): string => $payload['state_key']);
        }

        public function send(array $messages): array
        {
            return ($this->send)($messages);
        }
    });
}

function latestConcurrencyRecord($property, string $subjectId, string $stateKey, int $version): OutboxMessage
{
    return inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'latest-channel', (object) ['type' => 'availability', 'id' => $subjectId], 'availability.updated',
        ['version' => $version, 'state_key' => $stateKey], (string) $version,
    )));
}

test('a second worker cannot claim a latest-only key while the first is in flight', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    $second = null;
    registerConcurrencyAdapter(function (array $messages) use (&$calls, &$second, $property): array {
        $calls[] = $messages[0]->payload['version'];
        if (count($calls) === 1) {
            $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);
            inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));
        }

        return [new DeliveryResult('accepted')];
    });
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));

    expect($calls)->toBe([1])
        ->and($first->fresh()->status)->toBe('delivered')
        ->and($second->fresh()->status)->toBe('pending');
});

test('a second worker can deliver after the in-flight message completes', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    $second = null;
    registerConcurrencyAdapter(function (array $messages) use (&$calls, &$second, $property): array {
        $calls[] = $messages[0]->payload['version'];
        if (count($calls) === 1) {
            $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);
        }

        return [new DeliveryResult('accepted')];
    });
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));

    expect($calls)->toBe([1, 2])
        ->and($first->fresh()->status)->toBe('delivered')
        ->and($second->fresh()->status)->toBe('delivered');
});
//

test('latest-only pending update is not replaced after another worker has claimed it', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    $second = null;
    registerConcurrencyAdapter(function (array $messages) use (&$calls, &$second, $property): array {
        $calls[] = $messages[0]->payload['version'];
        if ($second === null) {
            $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);
        }

        return [new DeliveryResult('accepted')];
    });
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));

    expect($first->fresh()->status)->toBe('delivered')
        ->and($second->fresh()->status)->toBe('pending')
        ->and($calls)->toBe([1]);
});

test('latest-only lock table coordinates coalesce updates', function () {
    [, , $property] = organizationWithOwner();
    registerConcurrencyAdapter(fn (array $messages): array => [new DeliveryResult('accepted')]);
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);
    $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);

    expect($first->fresh()->status)->toBe('superseded')
        ->and($second->fresh()->status)->toBe('pending');
});

test('latest-only claim will not take another sending or unknown message for the key', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    registerConcurrencyAdapter(function (array $messages) use (&$calls): array {
        $calls[] = $messages[0]->payload['version'];

        return [new DeliveryResult($messages[0]->payload['version'] === 1 ? 'unknown' : 'accepted')];
    });
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));
    $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));

    expect($calls)->toBe([1])->and($second->fresh()->status)->toBe('pending');
});

test('delivery claim checks prevent concurrent in-flight sends for a shared latest-only key', function () {
    [, , $property] = organizationWithOwner();
    $calls = [];
    $second = null;
    registerConcurrencyAdapter(function (array $messages) use (&$calls, &$second, $property): array {
        $calls[] = $messages[0]->payload['version'];
        if (count($calls) === 1) {
            $second = latestConcurrencyRecord($property, 'availability-two', 'same-state', 2);
        }

        return [new DeliveryResult('accepted')];
    });
    $first = latestConcurrencyRecord($property, 'availability-one', 'same-state', 1);

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));

    expect($calls)->toBe([1, 2]);
});
