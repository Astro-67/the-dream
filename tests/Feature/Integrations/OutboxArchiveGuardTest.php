<?php

use App\Domains\Tenancy\Actions\ArchiveProperty;
use App\Domains\Tenancy\Exceptions\ArchiveRefused;
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
})->with(['pending', 'sending', 'rejected', 'exhausted', 'blocked', 'unknown', 'exhausted_unknown']);

test('delivered outbox messages do not prevent archiving and unrelated archival restrictions remain effective', function () {
    $t = twoTenants();
    outboxMessageForArchiveGuard($t->a->otherProperty, 'delivered');

    inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $t->a->otherProperty));

    expect($t->a->otherProperty->fresh()->archived_at)->not->toBeNull();
});

test('recording an unresolved message and archiving are serialized by the property lock', function () {
    $t = twoTenants();
    $property = $t->a->otherProperty;

    inTenant($property, fn () => DB::transaction(function () use ($property) {
        $locked = $property->newQuery()->whereKey($property->id)->lockForUpdate()->firstOrFail();
        outboxMessageForArchiveGuard($locked, 'pending');
    }));

    expect(fn () => inTenant($t->a->organization, fn () => app(ArchiveProperty::class)->handle($t->a->owner, $property)))
        ->toThrow(ArchiveRefused::class);
    expect($property->fresh()->archived_at)->toBeNull();
});
