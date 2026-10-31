<?php

namespace App\Domains\Subscriptions\Actions;

use App\Domains\Subscriptions\Models\BillingDetails;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Saves Karafuu's billing details and payment accounts. Platform data, so it
 * is logged under `platform`. Issued invoices keep their own snapshot.
 */
class SaveBillingDetails
{
    /** @param array<string, mixed> $details validated BillingDetails::FIELDS */
    public function handle(User $actor, array $details): BillingDetails
    {
        return DB::transaction(function () use ($actor, $details) {
            $billing = BillingDetails::query()->lockForUpdate()->orderBy('created_at')->first() ?? new BillingDetails;
            $old = $billing->exists ? $billing->only(BillingDetails::FIELDS) : [];
            $billing->fill($details)->save();

            activity('platform')->causedBy($actor)->performedOn($billing)->event('billing_details_updated')
                ->withProperties(['old' => $old, 'attributes' => $billing->only(BillingDetails::FIELDS)])
                ->log('Billing details updated');

            return $billing;
        });
    }
}
