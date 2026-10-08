<?php

namespace App\Support\Outbox;

use Illuminate\Support\Facades\DB;

final class QuotaLimiter
{
    public function acquire(DeliveryPolicy $policy, int $requests = 1): bool
    {
        if ($policy->quotaIdentity === null || $policy->quotaLimit === null) {
            return true;
        }
        if ($requests < 1) {
            return false;
        }
        $identityHash = hash('sha256', $policy->quotaIdentity);

        return DB::transaction(function () use ($identityHash, $policy, $requests): bool {
            $now = now();
            DB::table('outbox_quotas')->insertOrIgnore([
                'identity_hash' => $identityHash,
                'allowance' => $policy->quotaLimit,
                'used' => 0,
                'window_started_at' => $now,
                'window_ends_at' => $now->copy()->addSeconds($policy->quotaWindowSeconds),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $quota = DB::table('outbox_quotas')->where('identity_hash', $identityHash)->lockForUpdate()->first();
            if ($quota === null) {
                return false;
            }
            if ($policy->quotaLimit !== $quota->allowance) {
                throw new \LogicException('An outbox quota identity must keep its declared allowance.');
            }
            if ($now->greaterThanOrEqualTo($quota->window_ends_at)) {
                $quota->used = 0;
                $quota->window_started_at = $now;
                $quota->window_ends_at = $now->copy()->addSeconds($policy->quotaWindowSeconds);
            }
            if ($quota->used + $requests > $policy->quotaLimit) {
                return false;
            }
            DB::table('outbox_quotas')->where('identity_hash', $identityHash)->update([
                'used' => $quota->used + $requests,
                'window_started_at' => $quota->window_started_at,
                'window_ends_at' => $quota->window_ends_at,
                'updated_at' => $now,
            ]);

            return true;
        }, attempts: 5);
    }
}
