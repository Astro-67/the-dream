<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\Billing\Actions\ReversePosting;
use App\Domains\Billing\Models\Folio;
use App\Domains\Billing\Models\Receipt;
use App\Domains\FiscalReceipting\CheckoutReport;
use App\Domains\FiscalReceipting\Enums\VfmsMode;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
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
    /** @param array{link_existing?: bool, booking_number?: string, receipt_number?: string, reason?: string, name?: string, reg_number?: string|null, email?: string|null, phone?: string|null, is_zra_registered?: bool} $details */
    public function handle(User $actor, Receipt $receipt, array $details): void
    {
        $payment = $receipt->posting()->firstOrFail();
        Audit::validationRefusal($actor, $receipt, 'Advance payment filing refused', fn () => DB::transaction(function () use ($actor, $receipt, $details, $payment) {
            $folio = Folio::query()->whereKey($payment->folio_id)->lockForUpdate()->firstOrFail();
            $reservation = Reservation::query()->whereKey($folio->reservation_id)->lockForUpdate()->firstOrFail();
            $existing = VfmsAdvance::latest($reservation, lock: true);
            $linked = VfmsAdvance::linked($reservation, lock: true);
            $property = Property::query()->findOrFail($reservation->property_id);
            if (! empty($details['link_existing'])) {
                if ($linked !== null && ($linked->snapshot['posting_id'] ?? null) !== $payment->id) {
                    throw ValidationException::withMessages(['vfms' => __('An existing advance is already linked to another payment on this reservation.')]);
                }
                $reason = VfmsAdvance::linkIneligible($payment, $reservation, $property, lock: true);
                if ($reason !== null) {
                    throw ValidationException::withMessages(['vfms' => $reason]);
                }
                $mode = VfmsCredential::for($property)->mode;
                $references = [
                    'bookingNumber' => $details['booking_number'], 'receiptNumber' => $details['receipt_number'],
                    'mode' => $mode->value, 'source' => 'external',
                    'receiptUrl' => CheckoutReport::viewerUrl($mode === VfmsMode::Live, 'receipt-form', $details['receipt_number']),
                ];
                if ($linked !== null && $linked->snapshot['advance_booking'] == $references) {
                    return;
                }
                app(RecordLifecycleEvent::class)->handle($reservation, 'advance_booking_linked', $actor, [
                    'posting_id' => $payment->id, 'advance_booking' => $references, 'reason' => $details['reason'],
                ]);
                Audit::record($actor, 'advance_booking_linked', $receipt, 'Existing ZRA advance references linked, not independently verified', [
                    'posting_id' => $payment->id, 'reason' => $details['reason'], 'mode' => $mode->value,
                ]);

                return;
            }
            if ($linked !== null) {
                throw ValidationException::withMessages(['vfms' => __('An existing ZRA advance is linked. Do not create a second advance receipt.')]);
            }
            if ($existing !== null) {
                if (($existing->payload['posting_id'] ?? null) !== $payment->id) {
                    throw ValidationException::withMessages(['vfms' => __('Only one advance filing per reservation is supported. Use the existing ZRA filing for recovery.')]);
                }

                return;
            }
            $reason = VfmsAdvance::ineligible($payment, $reservation, $property);
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
