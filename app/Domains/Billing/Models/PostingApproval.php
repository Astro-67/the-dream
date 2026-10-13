<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\PostingApprovalPolicy;
use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read User|null $requester
 * @property-read Folio $folio
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

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<Folio, $this> */
    public function folio(): BelongsTo
    {
        return $this->belongsTo(Folio::class);
    }
}
