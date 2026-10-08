<?php

namespace App\Support\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutboxAttempt extends Model
{
    use HasUlids;

    protected $fillable = [
        'outbox_message_id', 'attempt_number', 'outcome', 'request_body',
        'response_body', 'provider_references', 'retry_at',
    ];

    protected function casts(): array
    {
        return [
            'request_body' => 'encrypted',
            'response_body' => 'encrypted',
            'provider_references' => 'array',
            'retry_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<OutboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}
