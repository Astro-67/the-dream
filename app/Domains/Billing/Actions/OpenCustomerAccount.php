<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\CustomerAccount;
use App\Domains\Billing\Models\CustomerAccountSequence;
use App\Domains\Subscriptions\Entitlements;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

class OpenCustomerAccount
{
    /** @param array<string, mixed> $details */
    public function handle(User $actor, array $details): CustomerAccount
    {
        return Audit::validationRefusal($actor, new CustomerAccount, 'Customer account refused', fn () => DB::transaction(function () use ($actor, $details) {
            $property = Tenancy::property();
            app(Entitlements::class)->lockForNewSales($property->demo);
            Property::query()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $sequence = CustomerAccountSequence::query()->where('property_id', $property->id)->lockForUpdate()->first()
                ?? CustomerAccountSequence::query()->create(['last_number' => 0]);
            $sequence->increment('last_number');
            $currency = $details['currency'];
            unset($details['currency']);
            $account = CustomerAccount::query()->create([
                ...$details, 'number' => 'ACC-'.str_pad((string) $sequence->last_number, 6, '0', STR_PAD_LEFT), 'status' => 'open',
            ]);
            $account->folios()->create(['currency' => $currency]);
            Audit::record($actor, 'created', $account, 'Customer account opened');

            return $account;
        }, attempts: 3));
    }
}
