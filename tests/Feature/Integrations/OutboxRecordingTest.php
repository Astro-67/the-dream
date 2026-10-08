<?php

use App\Domains\Tenancy\Models\Property;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('integration recording requires the business transaction and dispatches only after commit', function () {
    [, , $property] = organizationWithOwner();
    $businessTable = 'outbox_recording_test_subjects';
    DB::statement("CREATE TABLE {$businessTable} (id VARCHAR(26) PRIMARY KEY, value VARCHAR(255))");

    inTenant($property, function () use ($businessTable, $property) {
        DB::transaction(function () use ($businessTable, $property) {
            DB::table($businessTable)->insert(['id' => '01J00000000000000000000000', 'value' => 'committed']);
            app(Outbox::class)->record(
                integration: 'fake',
                subject: (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
                event: 'reservation.changed',
                payload: ['guest_count' => 2],
                idempotencyKey: 'reservation-1',
            );
            expect(Queue::size())->toBe(0);
        });

        expect(OutboxMessage::query()->count())->toBe(1)
            ->and(Queue::size())->toBe(1);

        try {
            DB::transaction(function () use ($businessTable, $property) {
                DB::table($businessTable)->insert(['id' => '01J00000000000000000000001', 'value' => 'rolled back']);
                app(Outbox::class)->record(
                    integration: 'fake',
                    subject: (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'],
                    event: 'reservation.changed',
                    payload: ['guest_count' => 3],
                    idempotencyKey: 'reservation-2',
                );
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('rollback');
        }

        expect(DB::table($businessTable)->count())->toBe(1)
            ->and(OutboxMessage::query()->count())->toBe(1)
            ->and(Queue::size())->toBe(1);
    });
});

test('integration recording refuses calls outside an active transaction', function () {
    [, , $property] = organizationWithOwner();
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('Outbox messages must be recorded inside the caller transaction.');

    inTenant($property, fn () => app(Outbox::class)->record(
        integration: 'fake',
        subject: (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        event: 'reservation.changed',
        payload: ['guest_count' => 2],
        idempotencyKey: 'reservation-1',
    ));
});

test('recorded messages use the current property and keep encrypted payload data', function () {
    [, , $property] = organizationWithOwner();

    $message = inTenant($property, function () {
        return DB::transaction(fn () => app(Outbox::class)->record(
            integration: 'fake',
            subject: (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
            event: 'reservation.changed',
            payload: ['guest_count' => 2, 'guest_name' => 'Sensitive guest'],
            idempotencyKey: 'reservation-1',
        ));
    });

    expect($message->property_id)->toBe($property->id)
        ->and($message->organization_id)->toBe($property->organization_id)
        ->and($message->sequence)->toBe(1)
        ->and($message->idempotency_key)->toBe('reservation-1')
        ->and($message->payload)->toBe(['guest_count' => 2, 'guest_name' => 'Sensitive guest'])
        ->and(DB::table('outbox_messages')->value('payload'))->not->toContain('Sensitive guest');
});

test('sequence and idempotency uniqueness are scoped to a complete ordered stream', function () {
    [, , $property] = organizationWithOwner();

    $messages = inTenant($property, function () {
        return DB::transaction(function () {
            $outbox = app(Outbox::class);
            $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];
            $first = $outbox->record('fake', $subject, 'changed', ['version' => 1], 'same-key');
            $second = $outbox->record('fake', $subject, 'changed', ['version' => 2], 'same-key');
            $otherIntegration = $outbox->record('fake-two', $subject, 'changed', ['version' => 1], 'same-key');

            return [$first, $second, $otherIntegration];
        });
    });

    expect(array_column($messages, 'sequence'))->toBe([1, 2, 1])
        ->and($messages[0]->idempotency_key)->not->toBe($messages[1]->idempotency_key)
        ->and($messages[0]->stream_key)->toBe($messages[1]->stream_key)
        ->and($messages[2]->stream_key)->not->toBe($messages[0]->stream_key);
});
