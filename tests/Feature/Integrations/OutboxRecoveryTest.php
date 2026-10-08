<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('sweeper queues committed messages missed by the original dispatch', function () {
    [, , $property] = organizationWithOwner();
    config(['queue.default' => 'database']);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));
    DB::table('jobs')->delete();

    expect(app(OutboxSweeper::class)->sweep())->toBe(1)
        ->and(Queue::size())->toBe(1);
    $payload = json_decode(DB::table('jobs')->latest('id')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
    expect($payload['tenancy']['organization_id'])->toBe($property->organization_id)
        ->and($payload['tenancy']['property_id'])->toBe($property->id);
});

test('sweeper dispatches due retries but does not send future retries early', function () {
    [, , $property] = organizationWithOwner();
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));
    $message->status = 'retryable';
    $message->setAttribute('next_attempt_at', now()->addMinute());
    $message->save();

    expect(app(OutboxSweeper::class)->sweep())->toBe(0)
        ->and(Queue::size())->toBe(1);

    $message->setAttribute('next_attempt_at', now()->subSecond())->save();
    expect(app(OutboxSweeper::class)->sweep())->toBe(1)
        ->and(Queue::size())->toBe(2);
});

test('expired claims become unknown and preserve the in-flight attempt', function () {
    [, , $property] = organizationWithOwner();
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));
    $message->status = 'sending';
    $message->attempt_count = 1;
    $message->claim_token = str_repeat('a', 64);
    $message->claim_expires_at = now()->subSecond();
    $message->save();
    inTenant($property, fn () => OutboxAttempt::query()->create([
        'organization_id' => $property->organization_id,
        'property_id' => $property->id,
        'outbox_message_id' => $message->id,
        'attempt_number' => 1,
        'outcome' => 'sending',
        'request_body' => '{"v":1}',
    ]));

    expect(app(OutboxSweeper::class)->sweep())->toBe(0)
        ->and(inTenant($property, fn () => $message->fresh()->status))->toBe('unknown')
        ->and(inTenant($property, fn () => OutboxAttempt::query()->sole()->outcome))->toBe('unknown');
});

test('unsafe unknown outcomes hold while safe contracts retry the unchanged payload', function (bool $safe, string $expected) {
    [, , $property] = organizationWithOwner();
    $adapter = new class($safe) implements DeliveryAdapter
    {
        public array $payloads = [];

        public function __construct(private bool $safe) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(unknownRetrySafe: $this->safe);
        }

        public function send(array $messages): array
        {
            $this->payloads[] = $messages[0]->payload;

            return [new DeliveryResult(count($this->payloads) === 1 ? 'unknown' : 'accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));
    inTenant($property, fn () => app(\App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));
    $message->refresh();

    if ($safe) {
        expect($message->fresh()->status)->toBe('unknown');
        Carbon\Carbon::setTestNow(now()->addMinutes(3));
        expect(app(OutboxSweeper::class)->sweep())->toBe(1);
        inTenant($property, fn () => app(\App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));
        Carbon\Carbon::setTestNow();
    } else {
        expect(app(OutboxSweeper::class)->sweep())->toBe(0);
    }

    expect($message->fresh()->status)->toBe($expected)
        ->and($adapter->payloads)->toBe($safe ? [['v' => 1], ['v' => 1]] : [['v' => 1]]);
})->with([
    'unsafe is held' => [false, 'unknown'],
    'safe retries' => [true, 'delivered'],
]);

test('stale claim results cannot overwrite a newer claim outcome', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class($property) implements DeliveryAdapter
    {
        public function __construct(private $property) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy();
        }

        public function send(array $messages): array
        {
            inTenant($this->property, function () use ($messages): void {
                $message = OutboxMessage::query()->findOrFail($messages[0]->id);
                $message->claim_token = str_repeat('b', 64);
                $message->status = 'unknown';
                $message->save();
            });

            return [new DeliveryResult('accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));

    inTenant($property, fn () => app(\App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));

    expect($message->fresh()->status)->toBe('unknown')
        ->and($message->fresh()->claim_token)->toBe(str_repeat('b', 64))
        ->and(inTenant($property, fn () => OutboxAttempt::query()->sole()->outcome))->toBe('sending');
});

test('retry ceiling exhausts without changing confirmed rejection semantics', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(maxAttempts: 1);
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('retryable')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['v' => 1], 'key',
    )));

    inTenant($property, fn () => app(\App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));
    expect($message->fresh()->status)->toBe('exhausted')
        ->and($message->fresh()->attempt_count)->toBe(1);
});
