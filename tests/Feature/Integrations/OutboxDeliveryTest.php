<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxSweeper;
use App\Support\Outbox\QuotaLimiter;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('independent eligible streams can be sent as one policy-sized batch', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(batchGrouping: ['property_id'], maxBatchSize: 10);
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return array_map(fn ($message) => new DeliveryResult('accepted', messageId: $message->id), $messages);
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    [$first, $second] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', ['version' => 1], 'two'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch([$first->id, $second->id]));

    expect($adapter->calls)->toHaveCount(1)
        ->and($adapter->calls[0])->toHaveCount(2)
        ->and($first->fresh()->status)->toBe('delivered')
        ->and($second->fresh()->status)->toBe('delivered');
});

test('batches stop at the declared maximum size', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(batchGrouping: ['property_id'], maxBatchSize: 2);
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return array_map(fn ($message) => new DeliveryResult('accepted', messageId: $message->id), $messages);
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $messages = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', [], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', [], 'two'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000002'], 'changed', [], 'three'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch(array_map(fn ($message) => $message->id, $messages)));

    expect(array_map('count', $adapter->calls))->toBe([2, 1])
        ->and($messages[2]->fresh()->status)->toBe('delivered');
});

test('partial results update only the identified message', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(batchGrouping: ['property_id'], maxBatchSize: 10);
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return [
                new DeliveryResult('accepted', messageId: $messages[1]->id),
                new DeliveryResult('retryable', messageId: $messages[0]->id),
            ];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    [$first, $second] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', ['version' => 1], 'two'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch([$first->id, $second->id]));

    expect($first->fresh()->status)->toBe('retryable')
        ->and($second->fresh()->status)->toBe('delivered')
        ->and($adapter->calls)->toHaveCount(1);
});

test('ambiguous batch results leave all messages unknown', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(batchGrouping: ['property_id'], maxBatchSize: 10);
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('accepted'), new DeliveryResult('rejected')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    [$first, $second] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', ['version' => 1], 'two'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch([$first->id, $second->id]));

    expect($first->fresh()->status)->toBe('unknown')
        ->and($second->fresh()->status)->toBe('unknown');
});

test('a batch contains no more than one message from an ordered stream', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(batchGrouping: ['property_id'], maxBatchSize: 10);
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return array_map(fn ($message) => new DeliveryResult('accepted', messageId: $message->id), $messages);
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    [$first, $successor] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];

        return [
            $outbox->record('fake', $subject, 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', $subject, 'changed', ['version' => 2], 'two'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch([$first->id, $successor->id]));

    expect($adapter->calls)->toHaveCount(1)
        ->and($adapter->calls[0])->toHaveCount(1)
        ->and($first->fresh()->status)->toBe('delivered')
        ->and($successor->fresh()->status)->toBe('pending')
        ->and($successor->fresh()->attempt_count)->toBe(0);
});

test('single-message provider requests consume one quota unit each after successful claims', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(quotaIdentity: 'single-requests', quotaLimit: 2);
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return [new DeliveryResult('accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $messages = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', [], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', [], 'two'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000002'], 'changed', [], 'three'),
        ];
    }));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch(array_map(fn ($message) => $message->id, $messages)));

    expect($adapter->calls)->toHaveCount(2)
        ->and(array_map(fn ($message) => $message->fresh()->status, $messages))->toBe(['delivered', 'delivered', 'pending'])
        ->and(DB::table('outbox_quotas')->where('identity_hash', hash('sha256', 'single-requests'))->value('used'))->toBe(2);
});

test('a refused quota leaves single messages pending without burning allowance', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(quotaIdentity: 'full-quota', quotaLimit: 1);
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $messages = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);

        return [
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', [], 'one'),
            $outbox->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'changed', [], 'two'),
        ];
    }));
    app(QuotaLimiter::class)->acquire($adapter->policy());

    inTenant($property, fn () => app(DeliveryEngine::class)->deliverBatch(array_map(fn ($message) => $message->id, $messages)));

    expect(array_map(fn ($message) => $message->fresh()->status, $messages))->toBe(['pending', 'pending'])
        ->and(DB::table('outbox_quotas')->where('identity_hash', hash('sha256', 'full-quota'))->value('used'))->toBe(1);
});

test('a refused quota returns a claimed retry to its retryable state', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(quotaIdentity: 'retry-quota', quotaLimit: 1);
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', [], 'one',
    )));
    $message->forceFill(['status' => 'retryable', 'attempt_count' => 1, 'next_attempt_at' => now()->subMinute()])->save();
    app(QuotaLimiter::class)->acquire($adapter->policy());

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    expect($message->fresh())->status->toBe('retryable')->attempt_count->toBe(1);
});

test('a fake adapter delivers outside the claim transaction and records encrypted attempt history', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $observed = [];

        public int $transactionLevel = -1;

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            $this->transactionLevel = DB::transactionLevel();
            $this->observed = $messages;

            return [new DeliveryResult('accepted', ['provider_id' => 'provider-42'], ['receipt' => 'private response'])];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed',
        ['guest_name' => 'Sensitive guest'], 'stable-key',
    )));
    $outsideTransactionLevel = DB::transactionLevel();

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    $message->refresh();
    $attempt = inTenant($property, fn () => OutboxAttempt::query()->sole());
    expect($adapter->observed)->toHaveCount(1)
        ->and($adapter->transactionLevel)->toBe($outsideTransactionLevel)
        ->and($message->status)->toBe('delivered')
        ->and($message->provider_references)->toBe(['provider_id' => 'provider-42'])
        ->and($attempt->outcome)->toBe('accepted')
        ->and($attempt->request_body)->toContain('Sensitive guest')
        ->and($attempt->response_body)->toContain('private response')
        ->and(DB::table('outbox_attempts')->value('request_body'))->not->toContain('Sensitive guest')
        ->and(DB::table('outbox_attempts')->value('response_body'))->not->toContain('private response');
});

test('retryable delivery preserves message identity and waits until its retry time', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $payloads = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(maxAttempts: 3);
        }

        public function send(array $messages): array
        {
            $this->payloads[] = $messages[0]->payload;

            return [new DeliveryResult(count($this->payloads) === 1 ? 'retryable' : 'accepted')];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed',
        ['version' => 1], 'stable-key',
    )));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    expect($message->fresh()->status)->toBe('retryable')
        ->and($message->fresh()->attempt_count)->toBe(1)
        ->and($adapter->payloads)->toBe([['version' => 1]]);

    $message->setAttribute('next_attempt_at', now()->subSecond())->save();
    expect(app(OutboxSweeper::class)->sweep())->toBe(1);
    $message->refresh();
    $dispatchToken = $message->dispatch_claim_token;
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id, $dispatchToken));
    expect($message->fresh()->status)->toBe('delivered')
        ->and($message->fresh()->attempt_count)->toBe(2)
        ->and($adapter->payloads)->toBe([['version' => 1], ['version' => 1]]);
});

test('rejected, retryable and unknown results never mark a message delivered', function (string $outcome) {
    [, , $property] = organizationWithOwner();
    $adapter = new class($outcome) implements DeliveryAdapter
    {
        public function __construct(private string $outcome) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult($this->outcome)];
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed',
        ['version' => 1], 'stable-key',
    )));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    expect($message->fresh()->status)->toBe($outcome)
        ->and($message->fresh()->delivered_at)->toBeNull();
})->with(['rejected', 'retryable', 'unknown']);

test('only one ordered message in a stream is sent, and successors wait for acceptance', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements DeliveryAdapter
    {
        public array $calls = [];

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return [new DeliveryResult(count($this->calls) === 1 ? 'rejected' : 'accepted')];
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

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($second->id));
    expect($adapter->calls)->toBeEmpty()
        ->and($second->fresh()->status)->toBe('blocked');
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($first->id));
    expect($adapter->calls)->toHaveCount(1)
        ->and($first->fresh()->status)->toBe('rejected');

    // A confirmed rejection holds successors; a later acceptance is only possible through an authorized recovery path (#21).
});

test('unmappable adapter results become unknown outcomes', function (mixed $results) {
    [, , $property] = organizationWithOwner();
    $adapter = new class($results) implements DeliveryAdapter
    {
        public function __construct(private mixed $results) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function send(array $messages): array
        {
            return $this->results;
        }
    };
    app(DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed',
        ['version' => 1], 'stable-key',
    )));

    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    expect($message->fresh()->status)->toBe('unknown')
        ->and(inTenant($property, fn () => OutboxAttempt::query()->sole()->outcome))->toBe('unknown');
})->with([
    'no result' => ['results' => []],
    'extra result' => ['results' => [new DeliveryResult('accepted'), new DeliveryResult('accepted')]],
    'wrong result type' => ['results' => ['accepted']],
]);

test('delivery for one property is invisible from another property', function () {
    [, , $propertyA] = organizationWithOwner(['organization_name' => 'Alpha']);
    [, , $propertyB] = organizationWithOwner(['organization_name' => 'Bravo']);
    $message = inTenant($propertyA, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed',
        ['version' => 1], 'stable-key',
    )));

    expect(inTenant($propertyB, fn () => OutboxMessage::query()->find($message->id)))->toBeNull();
});
