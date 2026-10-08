<?php

use App\Domains\Platform\Enums\PlatformRole;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxAttempt;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

function rejectedOutboxMessage($property, string $integration = 'fake'): OutboxMessage
{
    $adapter = new class implements DeliveryAdapter
    {
        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy(unknownRetrySafe: false);
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('rejected')];
        }
    };
    app(DeliveryAdapters::class)->register($integration, $adapter);
    $message = inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        $integration, (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['version' => 1], 'stable-key',
    )));
    inTenant($property, fn () => app(DeliveryEngine::class)->deliver($message->id));

    return $message->fresh();
}

test('retry command requires actor property and reason', function () {
    expect(Artisan::call('outbox:retry', ['message' => 'missing']))->not->toBe(0);
});

test('a property manager restarts unchanged retryable work and audit contains metadata only', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
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
    app(DeliveryAdapters::class)->register('channel', $adapter);
    $message = inTenant($t->a->property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'channel', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'changed', ['private' => 'guest'], 'stable-key',
    )));
    inTenant($t->a->property, fn () => app(DeliveryEngine::class)->deliver($message->id));
    $message->setAttribute('next_attempt_at', now()->addDay())->save();

    $this->artisan('outbox:retry', [
        'message' => $message->id,
        '--actor' => $manager->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Provider confirmed retry window',
    ])->assertSuccessful();

    Artisan::call('queue:work', ['--once' => true]);

    expect($message->fresh()->status)->toBe('delivered')
        ->and($message->fresh()->payload)->toBe(['private' => 'guest'])
        ->and($message->fresh()->idempotency_key)->toBe($message->idempotency_key)
        ->and($adapter->payloads)->toBe([['private' => 'guest'], ['private' => 'guest']]);
    $audit = Activity::query()->where('event', 'outbox_recovery')->latest('id')->first();
    expect($audit?->causer_id)->toBe($manager->id)
        ->and($audit?->getExtraProperty('reason'))->toBe('Provider confirmed retry window')
        ->and(json_encode($audit?->properties))->not->toContain('guest');
});

test('owner cannot recover another organization property and refusal is audited', function () {
    $t = twoTenants();
    $message = rejectedOutboxMessage($t->b->property);

    $this->artisan('outbox:retry', [
        'message' => $message->id,
        '--actor' => $t->a->owner->id,
        '--property' => $t->b->property->id,
        '--reason' => 'Attempt cross organization recovery',
    ])->assertFailed();

    expect($message->fresh()->status)->toBe('rejected')
        ->and(Activity::query()->where('event', 'outbox_recovery_refused')->where('causer_id', $t->a->owner->id)->exists())->toBeTrue();
});

test('compliance operator cannot recover channel distribution work', function () {
    $t = twoTenants();
    $operator = platformStaff(PlatformRole::ComplianceOperator);
    $message = rejectedOutboxMessage($t->a->property, 'channel');

    $this->artisan('outbox:retry', [
        'message' => $message->id,
        '--actor' => $operator->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Attempt forbidden channel recovery',
    ])->assertFailed();

    expect($message->fresh()->status)->toBe('rejected')
        ->and(Activity::query()->where('event', 'outbox_recovery_refused')->where('causer_id', $operator->id)->exists())->toBeTrue();
});

test('only acceptance evidence can resolve unknown work as delivered', function () {
    $t = twoTenants();
    $message = rejectedOutboxMessage($t->a->property);

    $this->artisan('outbox:confirm-accepted', [
        'message' => $message->id,
        '--actor' => $t->a->owner->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Provider lookup',
    ])->assertFailed();

    expect($message->fresh()->status)->toBe('rejected');
});

test('raw body cannot be read using recovery permission', function () {
    $t = twoTenants();
    $manager = $t->a->member(OrganizationRole::PropertyManager);
    $message = rejectedOutboxMessage($t->a->property);

    $this->artisan('outbox:show-body', [
        'message' => $message->id,
        '--actor' => $manager->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Recovery investigation',
    ])->assertFailed();

    expect($message->fresh()->payload)->toBe(['version' => 1]);
});

test('recovery refuses an active attempt', function () {
    $t = twoTenants();
    $message = rejectedOutboxMessage($t->a->property);
    $message->status = 'sending';
    $message->claim_token = str_repeat('b', 64);
    $message->claim_expires_at = now()->addMinute();
    $message->save();

    $this->artisan('outbox:retry', [
        'message' => $message->id,
        '--actor' => $t->a->owner->id,
        '--property' => $t->a->property->id,
        '--reason' => 'Race check',
    ])->assertFailed();

    expect($message->fresh()->status)->toBe('sending');
});
