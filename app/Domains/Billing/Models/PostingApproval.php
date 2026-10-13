<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\PostingApprovalPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A proposed posting, kept outside the append-only ledger until approved.
 *
 * @property string $id
 * @property string $folio_id
 * @property string $requested_by
 * @property string|null $resolved_by
 * @property string|null $original_posting_id
 * @property string|null $posting_id
 * @property string $status
 * @property array<string, mixed> $posting
 * @property string|null $rejection_reason
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable(['folio_id', 'requested_by', 'resolved_by', 'original_posting_id', 'posting_id', 'idempotency_key', 'status', 'posting', 'rejection_reason', 'resolved_at'])]
#[UsePolicy(PostingApprovalPolicy::class)]
class PostingApproval extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['posting' => 'array', 'resolved_at' => 'immutable_datetime'];
    }
}
