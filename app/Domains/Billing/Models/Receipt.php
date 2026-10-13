<?php

namespace App\Domains\Billing\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 */
#[Fillable(['posting_id', 'sequence', 'number', 'vfms_receipt_number', 'issued_at'])]
class Receipt extends Model
{
    use BelongsToProperty, HasUlids;

    public const string LABEL = 'Non-fiscal receipt';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sequence' => 'integer', 'issued_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Posting, $this> */
    public function posting(): BelongsTo
    {
        return $this->belongsTo(Posting::class);
    }
}
