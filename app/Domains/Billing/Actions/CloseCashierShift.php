<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Enums\CashierShiftStatus;
use App\Domains\Billing\Models\CashierShift;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a cashier shift with the cash counted in each currency. The expected
 * amount (float plus cash taken minus cash paid out) is frozen beside the count;
 * any variance needs a reason. A closed shift takes no more cash.
 */
class CloseCashierShift
{
    /** @param array<string, int> $counted minor units keyed by currency code */
    public function handle(User $actor, CashierShift $shift, array $counted, ?string $reason): CashierShift
    {
        return Audit::validationRefusal($actor, $shift, 'Cashier shift close refused', fn () => DB::transaction(function () use ($actor, $shift, $counted, $reason) {
            // Locked like RecordPayment locks it, so no cash lands between the count and the close.
            $shift = CashierShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            if ($shift->status !== CashierShiftStatus::Open) {
                throw ValidationException::withMessages(['shift' => __('This shift is already closed.')]);
            }
            $expected = $shift->expectedCash();
            foreach ($counted as $amount) {
                if ($amount < 0) {
                    throw ValidationException::withMessages(['counted' => __('Enter a count of zero or more.')]);
                }
            }
            $missing = array_diff_key($expected, $counted);
            if ($missing !== []) {
                throw ValidationException::withMessages(['counted' => __('Count the cash in :currencies.', ['currencies' => implode(', ', array_keys($missing))])]);
            }
            $counted = array_map('intval', $counted) + array_map(fn () => 0, $expected);
            ksort($counted);
            $expected += array_map(fn () => 0, $counted);
            ksort($expected);
            $reason = $reason === null || trim($reason) === '' ? null : trim($reason);
            if ($counted !== $expected && $reason === null) {
                throw ValidationException::withMessages(['variance_reason' => __('Give a reason for the difference.')]);
            }
            $shift->update(['status' => CashierShiftStatus::Closed, 'open_user_id' => null, 'expected' => $expected, 'counted' => $counted, 'variance_reason' => $reason, 'closed_at' => now()]);
            Audit::record($actor, 'closed', $shift, 'Cashier shift closed', ['expected' => $expected, 'counted' => $counted, 'variance' => $shift->variance(), 'reason' => $reason]);

            return $shift;
        }));
    }
}
