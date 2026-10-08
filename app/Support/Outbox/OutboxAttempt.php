<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OutboxAttempt extends Model
{
    use BelongsToProperty;
    use HasUlids;

    protected $fillable = [
        'organization_id', 'property_id', 'outbox_message_id', 'attempt_number', 'outcome', 'request_body',
        'response_body', 'provider_references', 'retry_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'request_body' => 'encrypted',
            'response_body' => 'encrypted',
            'provider_references' => 'encrypted:array',
            'retry_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $attempt): void {
            if ($attempt->getOriginal('outcome') !== 'sending') {
                throw new LogicException('Outbox attempt history is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Outbox attempt history is immutable.'));
    }

    /** @return BelongsTo<OutboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}
