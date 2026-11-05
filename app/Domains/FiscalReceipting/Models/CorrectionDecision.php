<?php

namespace App\Domains\FiscalReceipting\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * An observed ZRA decision on a correction: pending, verification, approved or rejected. Append-only.
 *
 * @property string $status
 */
#[Fillable(['document_correction_id', 'correction_decision_read_id', 'status'])]
class CorrectionDecision extends Model
{
    use BelongsToProperty;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Observed ZRA decisions are never rewritten.'));
        static::deleting(fn () => throw new LogicException('Observed ZRA decisions are never deleted.'));
    }
}
