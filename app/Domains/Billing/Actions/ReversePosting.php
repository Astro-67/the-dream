<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\CashierShiftStatus;
use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\FrontDesk\BusinessDate;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a posting by posting its exact negative, linked to it and with a reason
 * (FIN-02). The original is never changed; a posting is reversed at most once, and
 * a posting that itself cancels another (a reversal, a transfer out) is never reversed.
 */
class ReversePosting
{
    public function handle(?User $actor, Posting $original, string $reason): Posting|PostingApproval
    {
        return Audit::validationRefusal($actor, $original, 'Reversal refused', fn () => DB::transaction(function () use ($actor, $original, $reason) {
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('A reversal needs a reason.')]);
            }
            $folio = Folio::query()->whereKey($original->folio_id)->lockForUpdate()->firstOrFail();
            $original = Posting::query()->findOrFail($original->id);
            self::ensureCancellable($original);
            if ($original->cashier_shift_id !== null) {
                $shift = CashierShift::query()->whereKey($original->cashier_shift_id)->lockForUpdate()->firstOrFail();
                if ($shift->status !== CashierShiftStatus::Open) {
                    throw ValidationException::withMessages(['posting' => __('This cash posting belongs to a closed shift and cannot be reversed.')]);
                }
            }
            $rejected = PostingApproval::query()->where('original_posting_id', $original->id)->where('status', 'rejected')->count();

            return app(SubmitControlledPosting::class)->handle($actor, $folio, self::negative($original, [
                'folio_id' => $original->folio_id, 'type' => 'reversal', 'reason' => $reason,
                'method' => $original->method, 'cashier_shift_id' => $original->cashier_shift_id,
                'idempotency_key' => 'reversal:'.$original->id.($rejected > 0 ? ':'.$rejected : ''), 'actor_id' => $actor?->id,
            ]));
        }));
    }

    /** Refuses a posting that already cancels another, or that something already cancels. */
    public static function ensureCancellable(Posting $posting, string $field = 'posting', ?string $exceptApproval = null): void
    {
        if (in_array($posting->type, ['reversal', 'transfer_out'], true)) {
            throw ValidationException::withMessages([$field => __('This posting cancels another and cannot be reversed or moved.')]);
        }
        if (Posting::query()->where('related_posting_id', $posting->id)->exists()) {
            throw ValidationException::withMessages([$field => __('This posting has already been reversed or moved.')]);
        }
        if (PostingApproval::query()->where('original_posting_id', $posting->id)->where('status', 'pending')
            ->when($exceptApproval !== null, fn ($query) => $query->whereKeyNot($exceptApproval))->exists()) {
            throw ValidationException::withMessages([$field => __('This posting is awaiting reversal approval.')]);
        }
    }

    /**
     * The posting's figures negated, for a reversal or a transfer out.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function negative(Posting $original, array $attributes): array
    {
        return [
            'related_posting_id' => $original->id, 'category' => $original->category, 'description' => $original->description,
            'quantity' => $original->quantity, 'unit_price' => $original->unit_price, 'net' => -$original->net, 'amount' => -$original->amount,
            'currency' => $original->currency, 'tax_lines' => array_map(fn (array $line) => [...$line, 'amount' => -$line['amount']], $original->tax_lines),
            'tax_rule_ids' => $original->tax_rule_ids, 'business_date' => BusinessDate::of($original), 'posted_at' => now(), ...$attributes,
        ];
    }
}
