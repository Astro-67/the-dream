<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\VfmsAdvance;
use App\Domains\Reservations\Actions\RecordLifecycleEvent;
use App\Domains\Reservations\Models\Reservation;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FileAdvancePayment
{
    /** @param array{email: string, phone: string, is_zra_registered: bool} $details */
    public function handle(User $actor, Receipt $receipt, array $details): void
    {
        $payment = $receipt->posting()->firstOrFail();
        Audit::validationRefusal($actor, $receipt, 'Advance payment filing refused', fn () => DB::transaction(function () use ($actor, $receipt, $details, $payment) {
            $folio = Folio::query()->whereKey($payment->folio_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->whereKey($folio->reservation_id)->lockForUpdate()->firstOrFail();
            $existing = VfmsAdvance::latest($reservation, lock: true);
            if ($existing !== null) {
                if (($existing->payload['posting_id'] ?? null) !== $payment->id) {
                    throw ValidationException::withMessages(['vfms' => __('Only one advance filing per reservation is supported. Use the existing ZRA filing for recovery.')]);
                }

                return;
            }
            $reason = VfmsAdvance::ineligible($payment, $reservation, Property::query()->findOrFail($reservation->property_id));
            if ($reason !== null) {
                throw ValidationException::withMessages(['vfms' => $reason]);
            }
            ReversePosting::ensureCancellable($payment);
            app(RecordLifecycleEvent::class)->handle($reservation, 'advance_payment_recorded', $actor, [
                'posting_id' => $payment->id, 'advance_payment' => $details,
            ]);
            Audit::record($actor, 'advance_payment_filed', $receipt, 'Advance payment queued for VFMS', ['posting_id' => $payment->id]);
        }, attempts: 3));
    }
}
