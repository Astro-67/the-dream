<?php

use App\Support\Outbox\DeliveryAdapter;
use App\Support\Outbox\DeliveryAdapters;
use App\Support\Outbox\DeliveryEngine;
use App\Support\Outbox\DeliveryPolicy;
use App\Support\Outbox\DeliveryResult;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxCorrectionAdapter;
use App\Support\Outbox\OutboxMessage;
use App\Support\Outbox\OutboxPayloadRevision;
use App\Support\Outbox\OutboxRecovery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

function correctionAdapter(bool $safe = true, bool $failSuccessor = false): DeliveryAdapter
{
    return new class($safe, $failSuccessor) implements DeliveryAdapter, OutboxCorrectionAdapter
    {
        public function __construct(private bool $safe, private bool $failSuccessor) {}

        public function policy(): DeliveryPolicy
        {
            return new DeliveryPolicy;
        }

        public function changedPayloadKeyReuseSafe(): bool
        {
            return $this->safe;
        }

        public function correct(OutboxMessage $rejected, array $correction): array
        {
            return ['reservation' => $rejected->payload['reservation'], 'state' => $rejected->event, ...$correction];
        }

        public function rebuildSuccessor(OutboxMessage $successor, array $correctedRejectedPayload): array
        {
            if ($this->failSuccessor) {
                throw new RuntimeException('successor rebuild failed');
            }

            return [...$successor->payload, 'guest_count' => $correctedRejectedPayload['guest_count']];
        }

        public function send(array $messages): array
        {
            return [new DeliveryResult('rejected')];
        }
    };
}

function correctionStream($property, DeliveryAdapter $adapter): array
{
    app(DeliveryAdapters::class)->register('correction-fake', $adapter);
    [$rejected, $successor] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];

        return [
            $outbox->record('correction-fake', $subject, 'reservation-modified', ['reservation' => 'R-42', 'guest_count' => 1], 'first'),
            $outbox->record('correction-fake', $subject, 'reservation-checked-in', ['reservation' => 'R-42', 'state' => 'checked-in', 'guest_count' => 1], 'second'),
        ];
    }));
    inTenant($property, function () use ($rejected, $successor): void {
        app(DeliveryEngine::class)->deliver($rejected->id);
        app(DeliveryEngine::class)->deliver($successor->id);
    });

    return [$rejected->fresh(), $successor->fresh()];
}

test('authorized correction preserves operation meaning and rebuilds blocked successor snapshots', function () {
    [$owner, , $property] = organizationWithOwner();
    [$rejected, $successor] = correctionStream($property, correctionAdapter());
    expect($rejected->status)->toBe('rejected')->and($successor->status)->toBe('blocked');

    app(OutboxRecovery::class)->correct($owner, $property->id, $rejected->id, 'Correct guest registration', ['guest_count' => 2]);

    expect(inTenant($property, fn () => $rejected->fresh()))->status->toBe('pending')
        ->and(inTenant($property, fn () => $rejected->fresh()->payload))->toBe(['reservation' => 'R-42', 'state' => 'reservation-modified', 'guest_count' => 2])
        ->and(inTenant($property, fn () => $rejected->fresh()->sequence))->toBe(1)
        ->and(inTenant($property, fn () => $rejected->fresh()->idempotency_key))->toBe($rejected->idempotency_key)
        ->and(inTenant($property, fn () => $successor->fresh()->status))->toBe('blocked')
        ->and(inTenant($property, fn () => $successor->fresh()->payload))->toBe(['reservation' => 'R-42', 'state' => 'checked-in', 'guest_count' => 2])
        ->and(inTenant($property, fn () => $rejected->revisions()->sole()->payload))->toBe(['reservation' => 'R-42', 'guest_count' => 1])
        ->and(inTenant($property, fn () => $successor->revisions()->sole()->payload))->toBe(['reservation' => 'R-42', 'state' => 'checked-in', 'guest_count' => 1]);
});

test('correction refuses when the adapter has not verified changed-payload key reuse', function () {
    [$owner, , $property] = organizationWithOwner();
    [$rejected] = correctionStream($property, correctionAdapter(false));

    expect(fn () => app(OutboxRecovery::class)->correct($owner, $property->id, $rejected->id, 'Provider contract unknown', ['guest_count' => 2]))
        ->toThrow(RuntimeException::class, 'has not verified changed-payload idempotency-key reuse.');
    expect(inTenant($property, fn () => $rejected->fresh()->status))->toBe('rejected')
        ->and(inTenant($property, fn () => $rejected->revisions()->count()))->toBe(0);
});

test('correction rejects active attempts and every status other than confirmed rejection', function (string $status) {
    [$owner, , $property] = organizationWithOwner();
    [$rejected] = correctionStream($property, correctionAdapter());
    inTenant($property, function () use ($rejected, $status): void {
        $message = $rejected->fresh();
        $message->status = $status;
        if ($status === 'sending') {
            $message->claim_token = str_repeat('a', 64);
            $message->claim_expires_at = now()->addMinute();
        }
        $message->save();
    });

    expect(fn () => app(OutboxRecovery::class)->correct($owner, $property->id, $rejected->id, 'State check', ['guest_count' => 2]))
        ->toThrow(RuntimeException::class);

    expect(inTenant($property, fn () => $rejected->fresh()->status))->toBe($status);
})->with(['sending', 'unknown', 'delivered']);

test('failure rebuilding any blocked successor rolls back revisions, payloads, state and release', function () {
    [$owner, , $property] = organizationWithOwner();
    [$rejected, $successor] = correctionStream($property, correctionAdapter(failSuccessor: true));
    Queue::fake();

    expect(fn () => app(OutboxRecovery::class)->correct($owner, $property->id, $rejected->id, 'Rollback correction', ['guest_count' => 2]))
        ->toThrow(RuntimeException::class, 'successor rebuild failed');

    expect(inTenant($property, fn () => $rejected->fresh()->status))->toBe('rejected')
        ->and(inTenant($property, fn () => $rejected->fresh()->payload))->toBe(['reservation' => 'R-42', 'guest_count' => 1])
        ->and(inTenant($property, fn () => $successor->fresh()->status))->toBe('blocked')
        ->and(inTenant($property, fn () => OutboxPayloadRevision::query()->count()))->toBe(0);
    Queue::assertNothingPushed();
});
