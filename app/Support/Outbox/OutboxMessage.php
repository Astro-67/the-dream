<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    use BelongsToProperty;
    use HasUlids;

    protected $fillable = [
        'integration', 'subject_type', 'subject_id', 'sequence', 'stream_key',
        'idempotency_key', 'event', 'payload', 'status', 'attempt_count',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'provider_references' => 'array',
            'claim_expires_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    public function attempts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(OutboxAttempt::class);
    }
}
