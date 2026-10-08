<?php

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Exceptions\ArchiveRefused;
use App\Support\Outbox\Outbox;
use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

function outboxMessageForArchiveGuard($property, string $status): OutboxMessage
{
    return Tenancy::within(TenantContext::forProperty($property), fn () => OutboxMessage::query()->create([
        'integration' => 'fake',
        'subject_type' => 'reservation',
        'subject_id' => '01J00000000000000000000000',
        'sequence' => 1,
        'stream_key' => hash('sha256', $property->id.$status),
        'idempotency_key' => $property->id.$status,
        'event' => 'changed',
        'payload' => ['sensitive' => 'guest name must not be disclosed'],
        'status' => $status,
    ]));
}

test('unresolved outbox messages prevent archiving without exposing payloads or other properties', function (string $status) {
    $t = twoTenants();
    outboxMessageForArchiveGuard($t->a->otherProperty, $status);
    outboxMessageForArchiveGuard($t->b->property, 'pending');

    try {
        inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty));
        $this->fail('Expected unresolved integration delivery to refuse archival.');
    } catch (ArchiveRefused $refused) {
        expect($refused->getMessage())->toContain('unresolved integration messages')
            ->not->toContain('guest name')
            ->not->toContain($t->b->property->id);
    }

    expect($t->a->otherProperty->fresh()->archived_at)->toBeNull()
        ->and(Tenancy::within(TenantContext::forProperty($t->a->otherProperty), fn () => OutboxMessage::query()->count()))->toBe(1)
        ->and(Activity::query()->where('event', 'archive_refused')->where('subject_id', $t->a->otherProperty->id)->first()?->description)->not->toContain('guest name');
})->with(['pending', 'sending', 'retryable', 'rejected', 'exhausted', 'blocked', 'unknown', 'exhausted_unknown']);

test('unresolved messages on another property in the same organization do not prevent archiving', function () {
    $t = twoTenants();
    outboxMessageForArchiveGuard($t->a->property, 'pending');

    expect(fn () => inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty)))
        ->not->toThrow(ArchiveRefused::class);

    expect($t->a->otherProperty->fresh()->archived_at)->not->toBeNull();
});

test('delivered and superseded outbox messages do not prevent archiving', function (string $status) {
    $t = twoTenants();
    outboxMessageForArchiveGuard($t->a->otherProperty, $status);

    inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty));

    expect($t->a->otherProperty->fresh()->archived_at)->not->toBeNull();
})->with(['delivered', 'superseded']);

test('the archive guard preserves the existing last active property restriction', function () {
    $t = twoTenants();
    outboxMessageForArchiveGuard($t->a->property, 'delivered');
    inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty));

    expect(fn () => inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->property)))
        ->toThrow(ArchiveRefused::class, 'only active property');
});

test('recording for an archived property is refused without creating a message', function () {
    $t = twoTenants();
    $property = $t->a->otherProperty;
    inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $property));

    expect(fn () => inTenant($property, fn () => DB::transaction(fn () => app(Outbox::class)->record(
        'fake',
        (object) ['type' => 'reservation', 'id' => '01J00000000000000000000000'],
        'changed',
        ['version' => 1],
        'operation',
    ))))->toThrow(LogicException::class, 'archived property');

    expect(Tenancy::within(TenantContext::forProperty($property), fn () => OutboxMessage::query()->count()))->toBe(0);
});

test('recording and archival use a shared property-row lock', function () {
    $t = twoTenants();
    $property = $t->a->otherProperty;

    inTenant($property, fn () => DB::transaction(function () use ($property) {
        $property->newQuery()->whereKey($property->id)->lockForUpdate()->firstOrFail();
        outboxMessageForArchiveGuard($property, 'pending');
    }));

    expect(fn () => inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $property)))
        ->toThrow(ArchiveRefused::class);
    expect($property->fresh()->archived_at)->toBeNull();
});
