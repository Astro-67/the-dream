<?php

use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['queue.default' => 'database']);
});

test('integration recording dispatches after commit, and a business rollback removes the message and dispatch', function () {
    [, , $property] = organizationWithOwner();
    DB::statement('CREATE TABLE outbox_recording_test_subjects (id VARCHAR(26) PRIMARY KEY, value VARCHAR(255))');

    inTenant($property, function () {
        DB::transaction(function () {
            DB::table('outbox_recording_test_subjects')->insert(['id' => '01J00000000000000000000000', 'value' => 'committed']);
            app(Outbox::class)->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'], 'reservation.changed', ['guest_count' => 2], 'reservation-1');
            expect(Queue::size())->toBe(0);
        });

        expect(OutboxMessage::query()->count())->toBe(1)
            ->and(Queue::size())->toBe(1);

        try {
            DB::transaction(function () {
                DB::table('outbox_recording_test_subjects')->insert(['id' => '01J00000000000000000000001', 'value' => 'rolled back']);
                app(Outbox::class)->record('fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000001'], 'reservation.changed', ['guest_count' => 3], 'reservation-2');
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('rollback');
        }

        expect(DB::table('outbox_recording_test_subjects')->count())->toBe(1)
            ->and(OutboxMessage::query()->count())->toBe(1)
            ->and(Queue::size())->toBe(1);
    });
});

test('integration recording refuses calls outside an active transaction', function () {
    [, , $property] = organizationWithOwner();
    DB::commit(); // End RefreshDatabase's outer test transaction to exercise the precondition.

    expect(fn () => inTenant($property, fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'reservation.changed', ['guest_count' => 2], 'reservation-1',
    )))->toThrow(LogicException::class, 'Outbox messages must be recorded inside the caller transaction.');
});

test('recorded messages use tenant stream identity, scoped keys and encrypted payload data', function () {
    [, , $property] = organizationWithOwner();

    [$first, $second, $otherIntegration] = inTenant($property, fn () => DB::transaction(function () {
        $outbox = app(Outbox::class);
        $subject = (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'];

        return [
            $outbox->record('fake', $subject, 'reservation.changed', ['guest_count' => 2, 'guest_name' => 'Sensitive guest'], 'operation'),
            $outbox->record('fake', $subject, 'reservation.changed', ['guest_count' => 3], 'operation'),
            $outbox->record('fake-two', $subject, 'reservation.changed', ['guest_count' => 2], 'operation'),
        ];
    }));

    expect($first->organization_id)->toBe($property->organization_id)
        ->and($first->property_id)->toBe($property->id)
        ->and($first->sequence)->toBe(1)
        ->and($second->sequence)->toBe(2)
        ->and($first->idempotency_key)->toBe('operation:1')
        ->and($first->idempotency_key)->not->toBe($second->idempotency_key)
        ->and($first->stream_key)->toBe($second->stream_key)
        ->and($otherIntegration->sequence)->toBe(1)
        ->and($otherIntegration->stream_key)->not->toBe($first->stream_key)
        ->and($first->payload)->toBe(['guest_count' => 2, 'guest_name' => 'Sensitive guest'])
        ->and(DB::table('outbox_messages')->where('id', $first->id)->value('payload'))->not->toContain('Sensitive guest');
});

test('recording a message for one property does not expose it in another property', function () {
    [, , $propertyA] = organizationWithOwner(['organization_name' => 'Alpha']);
    [, , $propertyB] = organizationWithOwner(['organization_name' => 'Bravo']);
    $message = inTenant($propertyA, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake', (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'reservation.changed', ['guest_count' => 2], 'operation',
    )));

    expect(inTenant($propertyB, fn () => OutboxMessage::query()->find($message->id)))->toBeNull();
});
