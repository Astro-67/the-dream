<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a posting to another folio of the same reservation and currency: a
 * transfer out on the source cancels it, linked to it, and a transfer in on the
 * target re-posts it, linked to the transfer out. No currency conversion, so a
 * folio in another currency is refused.
 */
class TransferPosting
{
    public function handle(?User $actor, Posting $original, Folio $target, string $reason): Posting
    {
        return Audit::validationRefusal($actor, $original, 'Transfer refused', fn () => DB::transaction(function () use ($actor, $original, $target, $reason) {
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('A transfer needs a reason.')]);
            }
            // Lock both folios in a fixed order, so opposite transfers cannot deadlock.
            $folios = Folio::query()->whereKey([$original->folio_id, $target->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $source = $folios->get($original->folio_id);
            $target = $folios->get($target->id);
            if ($source === null || $target === null || $source->is($target) || $source->reservation_id !== $target->reservation_id) {
                throw ValidationException::withMessages(['folio_id' => __('Choose another folio on this reservation.')]);
            }
            if ($source->currency !== $target->currency) {
                throw ValidationException::withMessages(['folio_id' => __('A charge can only move to a folio in the same currency.')]);
            }
            $original = Posting::query()->findOrFail($original->id);
            ReversePosting::ensureCancellable($original);
            $out = Posting::query()->create(ReversePosting::negative($original, [
                'folio_id' => $source->id, 'type' => 'transfer_out', 'reason' => $reason,
                'idempotency_key' => 'transfer:'.$original->id, 'actor_id' => $actor?->id,
            ]));
            $in = Posting::query()->create(ReversePosting::negative($out, [
                'folio_id' => $target->id, 'type' => 'transfer_in', 'reason' => $reason,
                'idempotency_key' => 'transfer:'.$out->id, 'actor_id' => $actor?->id,
            ]));
            Audit::record($actor, 'created', $in, 'Posting transferred', ['from_folio_id' => $source->id, 'to_folio_id' => $target->id, 'posting_id' => $original->id, 'amount' => $original->amount, 'currency' => $original->currency->value, 'reason' => $reason]);

            return $in;
        }));
    }
}
