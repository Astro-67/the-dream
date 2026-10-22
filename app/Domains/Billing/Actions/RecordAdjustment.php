<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CashierShift;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Posting;
use App\Domains\Billing\Models\PostingApproval;
use App\Domains\FrontDesk\BusinessDate;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordAdjustment
{
    /** @param array{amount: int, reason: string, idempotency_key: string, method?: string|null, reference?: string|null} $details */
    public function handle(?User $actor, Folio $folio, string $type, array $details): Posting|PostingApproval
    {
        return Audit::validationRefusal($actor, $folio, 'Adjustment refused', fn () => DB::transaction(function () use ($actor, $folio, $type, $details) {
            $folio = Folio::query()->whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $existing = Posting::query()->where('folio_id', $folio->id)->where('idempotency_key', $details['idempotency_key'])->first();
            if ($existing !== null) {
                return $existing;
            }
            $approval = PostingApproval::query()->where('folio_id', $folio->id)->where('idempotency_key', $details['idempotency_key'])->first();
            if ($approval !== null) {
                return $approval;
            }
            $amount = $details['amount'];
            $reason = trim($details['reason']);
            if (! in_array($type, ['refund', 'write_off'], true) || $reason === '' || $amount <= 0 || $amount > 1000000000000) {
                throw ValidationException::withMessages(['amount' => __('Enter a positive amount and a reason.')]);
            }
            if ($type === 'write_off' && $amount > $folio->balance()->amount) {
                throw ValidationException::withMessages(['amount' => __('A write-off cannot exceed the unpaid balance.')]);
            }
            $method = $type === 'refund' ? ($details['method'] ?? null) : null;
            if ($type === 'refund' && ! in_array($method, RecordPayment::METHODS, true)) {
                throw ValidationException::withMessages(['method' => __('Choose a refund method.')]);
            }
            $property = Property::query()->whereKey($folio->property_id)
                ->when($method === 'cash', fn ($query) => $query->lockForUpdate())->firstOrFail();
            if ($method === 'cash' && $actor === null) {
                throw ValidationException::withMessages(['method' => __('A cashier must record a cash refund.')]);
            }
            $requiresShift = $method === 'cash' && $property->settings()['payment_settings']['use_cashier_shifts'];
            $shift = $requiresShift
                ? CashierShift::query()->where('property_id', $property->id)->where('open_user_id', $actor->id)->lockForUpdate()->first() : null;
            if ($requiresShift && $shift === null) {
                throw ValidationException::withMessages(['method' => __('Open a cashier shift before refunding cash.')]);
            }

            return app(SubmitControlledPosting::class)->handle($actor, $folio, [
                'folio_id' => $folio->id, 'type' => $type, 'category' => $type, 'reason' => $reason,
                'quantity' => 1, 'unit_price' => $amount, 'net' => $type === 'refund' ? $amount : -$amount,
                'amount' => $type === 'refund' ? $amount : -$amount, 'currency' => $folio->currency,
                'method' => $method, 'reference' => $details['reference'] ?? null, 'cashier_shift_id' => $shift?->id,
                'tax_lines' => [], 'tax_rule_ids' => [], 'idempotency_key' => $details['idempotency_key'],
                'actor_id' => $actor?->id, 'business_date' => BusinessDate::of($property), 'posted_at' => now(),
            ]);
        }, attempts: 3));
    }
}
