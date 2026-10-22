<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\ReceiptPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Thedream's proof of one payment (glossary: Receipt). Always non-fiscal: a VFMS
 * fiscal receipt number (M3) is stored separately, never in $number.
 *
 * @property string $id
 * @property string $posting_id
 * @property int $sequence gap-free per property
 * @property string $number the property's receipt prefix plus the sequence
 * @property string|null $vfms_receipt_number
 * @property CarbonImmutable $issued_at
 * @property array<string, mixed>|null $summary the paid folio's posted totals right after this payment; null before snapshots existed
 */
#[Fillable(['posting_id', 'sequence', 'number', 'vfms_receipt_number', 'issued_at', 'summary'])]
#[UsePolicy(ReceiptPolicy::class)]
class Receipt extends Model
{
    use BelongsToProperty, HasUlids;

    public const string LABEL = 'Non-fiscal receipt';

    public $timestamps = false;

    protected static function booted(): void
    {
        // Proof of a past payment: only the VFMS number may be added later.
        static::updating(fn (Receipt $receipt) => $receipt->isDirty(['posting_id', 'sequence', 'number', 'issued_at', 'summary'])
            ? throw new LogicException('A receipt and its folio summary are never rewritten.') : null);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'issued_at' => 'immutable_datetime', 'summary' => 'array'];
    }

    /** @return BelongsTo<Posting, $this> */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }
}
