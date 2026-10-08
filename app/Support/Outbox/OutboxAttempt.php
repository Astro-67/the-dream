<?php

namespace App\Support\Outbox;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

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

    public function message(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }
}
