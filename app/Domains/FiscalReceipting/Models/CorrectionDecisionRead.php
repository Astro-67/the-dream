<?php

namespace App\Domains\FiscalReceipting\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * One section 2.13 read; written once when it finishes. Request and response are encrypted evidence.
 *
 * @property int $id
 * @property string $property_id
 * @property string $actor_id
 * @property array<string, mixed> $request_body
 * @property array<string, mixed>|null $response_body
 * @property 'observed'|'unresolved'|'failed'|null $outcome
 * @property CarbonImmutable|null $finished_at
 */
#[Fillable(['actor_id', 'request_body', 'response_body', 'outcome', 'finished_at'])]
class CorrectionDecisionRead extends Model
{
    use BelongsToProperty;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['request_body' => 'encrypted:array', 'response_body' => 'encrypted:array', 'finished_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $read) {
            if ($read->getOriginal('finished_at') !== null) {
                throw new LogicException('A finished ZRA status read is never rewritten.');
            }
        });
        static::deleting(fn () => throw new LogicException('ZRA status read evidence is never deleted.'));
    }
}
