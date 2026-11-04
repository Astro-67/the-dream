<?php

namespace App\Domains\FiscalReceipting\Models;

use App\Support\Outbox\OutboxMessage;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** An immutable pair of issued documents; delivery is on its outbox, not a ZRA approval. */
#[Fillable(['id', 'original_document_id', 'replacement_document_id', 'original_number', 'replacement_number', 'actor_id', 'reason', 'mode', 'unit_id', 'outbox_message_id'])]
class DocumentCorrection extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'unit_id' => 'integer'];
    }

    /** The latest observed ZRA decision; 'awaiting' once ERROR_SAVE was delivered; null before then. */
    public function decision(): ?string
    {
        return CorrectionDecision::query()->where('document_correction_id', $this->id)->latest('id')->value('status')
            ?? (OutboxMessage::query()->whereKey($this->outbox_message_id)->value('status') === 'delivered' ? 'awaiting' : null);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued-document correction pairs are never rewritten.'));
        static::deleting(fn () => throw new LogicException('Issued-document correction evidence is never deleted.'));
    }
}
