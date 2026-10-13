<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResolvePostingApproval
{
    public function handle(User $actor, PostingApproval $approval, string $decision, ?string $reason = null): PostingApproval
    {
        return Audit::validationRefusal($actor, $approval, 'Approval refused', fn () => DB::transaction(function () use ($actor, $approval, $decision, $reason) {
            $folio = Folio::query()->whereKey($approval->folio_id)->lockForUpdate()->firstOrFail();
            $approval = PostingApproval::query()->whereKey($approval->id)->lockForUpdate()->firstOrFail();
            if ($approval->requested_by === $actor->id) {
                throw ValidationException::withMessages(['approval' => __('A different user must decide this approval.')]);
            }
            if (! in_array($decision, ['approve', 'reject'], true) || ($decision === 'reject' && trim($reason ?? '') === '')) {
                throw ValidationException::withMessages(['reason' => __('A rejection needs a reason.')]);
            }
            if ($approval->status !== 'pending') {
                return $approval;
            }
            $attributes = $approval->posting;
            $posting = null;
            if ($decision === 'approve') {
                if ($attributes['type'] === 'write_off' && abs($attributes['amount']) > $folio->balance()->amount) {
                    throw ValidationException::withMessages(['approval' => __('The unpaid balance is now smaller than this write-off.')]);
                }
                if ($approval->original_posting_id !== null) {
                    ReversePosting::ensureCancellable(Posting::query()->findOrFail($approval->original_posting_id), exceptApproval: $approval->id);
                }
                if (($attributes['cashier_shift_id'] ?? null) !== null) {
                    $shift = CashierShift::query()->whereKey($attributes['cashier_shift_id'])->lockForUpdate()->firstOrFail();
                    if ($shift->status !== 'open' || ($attributes['type'] !== 'reversal' && $shift->open_user_id !== $approval->requested_by)) {
                        throw ValidationException::withMessages(['approval' => __('The requesting cashier shift is closed. Reject this request and submit it in an open shift.')]);
                    }
                }
                $property = Property::query()->findOrFail($folio->property_id);
                $posting = Posting::query()->create([...$attributes, 'posted_at' => now(), 'business_date' => now($property->timezone)->toDateString()]);
            }
            $approval->update([
                'status' => $decision === 'approve' ? 'approved' : 'rejected', 'resolved_by' => $actor->id,
                'resolved_at' => now(), 'posting_id' => $posting?->id,
                'rejection_reason' => $decision === 'reject' ? trim($reason) : null,
            ]);
            Audit::record($actor, 'updated', $approval, 'Posting approval resolved', ['result' => $approval->status, 'reason' => $approval->rejection_reason, 'posting_id' => $posting?->id]);

            return $approval;
        }, attempts: 3));
    }
}
