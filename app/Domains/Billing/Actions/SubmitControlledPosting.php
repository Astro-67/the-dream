<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\ApprovalThreshold;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Models\User;
use App\Support\Audit\Audit;

class SubmitControlledPosting
{
    /** @param array<string, mixed> $attributes */
    public function handle(?User $actor, Folio $folio, array $attributes): Posting|PostingApproval
    {
        $attributes['currency'] = $folio->currency->value;
        $attributes['posted_at'] = now()->toDateTimeString();
        $threshold = ApprovalThreshold::query()->where('currency', $folio->currency->value)->first();
        if ($threshold?->amount !== null && abs($attributes['amount']) > $threshold->amount) {
            $approval = PostingApproval::query()->create([
                'folio_id' => $folio->id, 'requested_by' => $actor?->id,
                'original_posting_id' => $attributes['related_posting_id'] ?? null,
                'idempotency_key' => $attributes['idempotency_key'], 'status' => 'pending', 'posting' => $attributes,
            ]);
            Audit::record($actor, 'created', $approval, 'Posting awaiting approval', ['folio_id' => $folio->id]);

            return $approval;
        }
        $posting = Posting::query()->create($attributes);
        Audit::record($actor, 'created', $posting, 'Controlled posting recorded', ['folio_id' => $folio->id, 'type' => $posting->type, 'amount' => $posting->amount, 'reason' => $posting->reason]);

        return $posting;
    }
}
