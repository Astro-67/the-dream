<?php

namespace App\Support\Outbox;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class OutboxPayloadRevision extends Model
{
    use BelongsToProperty;
    use HasUlids;

    protected $fillable = ['organization_id', 'property_id', 'outbox_message_id', 'revision', 'payload', 'reason'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array'];
    }

    /** @return BelongsTo<OutboxMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(OutboxMessage::class, 'outbox_message_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Outbox payload revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Outbox payload revisions are immutable.'));
    }
}
