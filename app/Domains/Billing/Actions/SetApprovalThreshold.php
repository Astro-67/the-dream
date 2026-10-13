<?php

namespace App\Domains\Billing\Actions;

use App\Domains\Billing\Models\ApprovalThreshold;
use App\Domains\Tenancy\Models\Property;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Tenancy\Tenancy;
use Illuminate\Support\Facades\DB;

class SetApprovalThreshold
{
    public function handle(?User $actor, string $currency, ?int $amount): ApprovalThreshold
    {
        return DB::transaction(function () use ($actor, $currency, $amount) {
            Property::query()->whereKey(Tenancy::property()?->id)->lockForUpdate()->firstOrFail();
            $threshold = ApprovalThreshold::query()->updateOrCreate(['currency' => $currency], ['amount' => $amount]);
            Audit::record($actor, 'updated', $threshold, 'Approval threshold set', ['currency' => $currency, 'amount' => $amount]);

            return $threshold;
        }, attempts: 3);
    }
}
