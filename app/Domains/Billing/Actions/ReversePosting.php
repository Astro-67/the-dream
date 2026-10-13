<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Tenancy\Models\Property;
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
    public function handle(?User $actor, Posting $original, string $reason): Posting
    {
        return Audit::validationRefusal($actor, $original, 'Reversal refused', fn () => DB::transaction(function () use ($actor, $original, $reason) {
            $reason = trim($reason);
            if ($reason === '') {
                throw ValidationException::withMessages(['reason' => __('A reversal needs a reason.')]);
            }
            Folio::query()->whereKey($original->folio_id)->lockForUpdate()->firstOrFail();
            $original = Posting::query()->findOrFail($original->id);
            self::ensureCancellable($original);
            $reversal = Posting::query()->create(self::negative($original, [
                'folio_id' => $original->folio_id, 'type' => 'reversal', 'reason' => $reason,
                'idempotency_key' => 'reversal:'.$original->id, 'actor_id' => $actor?->id,
            ]));
            Audit::record($actor, 'created', $reversal, 'Posting reversed', ['folio_id' => $original->folio_id, 'reverses' => $original->id, 'amount' => $reversal->amount, 'currency' => $reversal->currency->value, 'reason' => $reason]);

            return $reversal;
        }));
    }

    /** Refuses a posting that already cancels another, or that something already cancels. */
    public static function ensureCancellable(Posting $posting, string $field = 'posting'): void
    {
        if (in_array($posting->type, ['reversal', 'transfer_out'], true)) {
            throw ValidationException::withMessages([$field => __('This posting cancels another and cannot be reversed or moved.')]);
        }
        if (Posting::query()->where('related_posting_id', $posting->id)->exists()) {
            throw ValidationException::withMessages([$field => __('This posting has already been reversed or moved.')]);
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
            'tax_rule_ids' => $original->tax_rule_ids, 'business_date' => self::businessDate($original), 'posted_at' => now(), ...$attributes,
        ];
    }

    // ponytail: before Spec 7 owns the open business date, use the property's local date.
    private static function businessDate(Posting $posting): string
    {
        return now(Property::query()->findOrFail($posting->getAttribute('property_id'))->timezone)->toDateString();
    }
}
