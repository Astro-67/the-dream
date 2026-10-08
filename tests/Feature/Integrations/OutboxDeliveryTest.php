<?php

use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('a fake adapter delivers a claimed message outside the transaction and records immutable encrypted outcomes', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements App\Support\Outbox\DeliveryAdapter
    {
        public array $observed = [];

        public function send(array $messages): array
        {
            expect(DB::transactionLevel())->toBe(0);
            $this->observed = $messages;

            return [new App\Support\Outbox\DeliveryResult('accepted', ['provider_id' => 'provider-42'], ['receipt' => 'private response'])];
        }
    };
    app(App\Support\Outbox\DeliveryAdapters::class)->register('fake', $adapter);

    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'changed', ['guest_name' => 'Sensitive guest'], 'stable-key',
    )));

    inTenant($property, fn () => app(App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));

    $message->refresh();
    $attempt = OutboxAttempt::query()->sole();
    expect($adapter->observed)->toHaveCount(1)
        ->and($message->status)->toBe('delivered')
        ->and($message->provider_references)->toBe(['provider_id' => 'provider-42'])
        ->and($attempt->outcome)->toBe('accepted')
        ->and($attempt->request_body)->toContain('Sensitive guest')
        ->and($attempt->response_body)->toContain('private response')
        ->and(DB::table('outbox_attempts')->value('request_body'))->not->toContain('Sensitive guest')
        ->and(DB::table('outbox_attempts')->value('response_body'))->not->toContain('private response');
});

test('rejected, retryable and unknown adapter outcomes never mark a message delivered', function (string $outcome) {
    [, , $property] = organizationWithOwner();
    $adapter = new class($outcome) implements App\Support\Outbox\DeliveryAdapter
    {
        public function __construct(private string $outcome) {}

        public function send(array $messages): array
        {
            return [new App\Support\Outbox\DeliveryResult($this->outcome)];
        }
    };
    app(App\Support\Outbox\DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'changed', ['version' => 1], 'stable-key',
    )));

    inTenant($property, fn () => app(App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));

    expect($message->fresh()->status)->toBe($outcome)
        ->and($message->fresh()->delivered_at)->toBeNull();
})->with(['rejected', 'retryable', 'unknown']);

test('a failed ordered predecessor prevents a successor from reaching its adapter', function () {
    [, , $property] = organizationWithOwner();
    $adapter = new class implements App\Support\Outbox\DeliveryAdapter
    {
        public array $calls = [];

        public function send(array $messages): array
        {
            $this->calls[] = $messages;

            return [new App\Support\Outbox\DeliveryResult('rejected')];
        }
    };
    app(App\Support\Outbox\DeliveryAdapters::class)->register('fake', $adapter);

    [$first, $second] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];

        return [
            $outbox->record('fake', $subject, 'changed', ['version' => 1], 'one'),
            $outbox->record('fake', $subject, 'changed', ['version' => 2], 'two'),
        ];
    }));

    inTenant($property, fn () => app(App\Support\Outbox\DeliveryEngine::class)->deliver($second->id));

    expect($adapter->calls)->toBeEmpty()
        ->and($second->fresh()->status)->toBe('blocked');

    inTenant($property, fn () => app(App\Support\Outbox\DeliveryEngine::class)->deliver($first->id));
    expect($adapter->calls)->toHaveCount(1)
        ->and($first->fresh()->status)->toBe('rejected');
});

test('adapter results with missing or excess mappings become unknown outcomes', function (array $results) {
    [, , $property] = organizationWithOwner();
    $adapter = new class($results) implements App\Support\Outbox\DeliveryAdapter
    {
        public function __construct(private array $results) {}

        public function send(array $messages): array
        {
            return $this->results;
        }
    };
    app(App\Support\Outbox\DeliveryAdapters::class)->register('fake', $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'changed', ['version' => 1], 'stable-key',
    )));

    inTenant($property, fn () => app(App\Support\Outbox\DeliveryEngine::class)->deliver($message->id));

    expect($message->fresh()->status)->toBe('unknown')
        ->and(OutboxAttempt::query()->sole()->outcome)->toBe('unknown');
})->with([
    'no result' => fn () => [],
    'extra result' => fn () => [new App\Support\Outbox\DeliveryResult('accepted'), new App\Support\Outbox\DeliveryResult('accepted')],
]);
