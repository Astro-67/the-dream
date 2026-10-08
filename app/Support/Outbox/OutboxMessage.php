<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property array<string, mixed> $payload
 */
class OutboxMessage extends Model
{
    use BelongsToProperty;
    use HasUlids;

    protected $fillable = [
        'integration', 'subject_type', 'subject_id', 'sequence', 'stream_key',
        'idempotency_key', 'event', 'payload', 'status', 'attempt_count', 'coalesce_key_hash',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'provider_references' => 'array',
            'claim_expires_at' => 'immutable_datetime',
            'dispatch_claim_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<OutboxAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(OutboxAttempt::class);
    }

    /** @return HasMany<OutboxPayloadRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(OutboxPayloadRevision::class);
    }

    protected static function booted(): void
    {
        static::updating(function (self $message): void {
            if ($message->isDirty('payload') && $message->attempt_count > 0 && ! app(OutboxCorrectionContext::class)->active()) {
                throw new LogicException('Attempted outbox payloads cannot be changed.');
            }
        });
    }
}
