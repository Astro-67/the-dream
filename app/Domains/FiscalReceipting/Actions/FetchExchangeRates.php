<?php

namespace App\Domains\FiscalReceipting\Actions;

use App\Domains\FiscalReceipting\Adapters\HttpVfms;
use App\Domains\FiscalReceipting\Models\VfmsCredential;
use App\Support\Money\ExchangeRate;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;

/**
 * Stores today's ZRA exchange rates (FSC-09). The rates are national, so the first Live property
 * whose credentials ZRA accepts answers for all; null when none can.
 */
class FetchExchangeRates
{
    public function __construct(private HttpVfms $vfms) {}

    public function handle(string $date): ?ExchangeRate
    {
        // One fetch a day; the hourly schedule only retries a day that has none yet.
        $stored = ExchangeRate::query()->whereDate('date', $date)->first();
        if ($stored !== null) {
            return $stored;
        }
        $credentials = Tenancy::within(TenantContext::platform('ZRA exchange-rate fetch'), fn () => VfmsCredential::query()
            ->where('mode', 'live')->whereNull('credentials_refused_at')->orderBy('live_since')->get());
        foreach ($credentials as $credential) {
            $rates = $this->vfms->exchangeRates($credential);
            if ($rates !== null && (float) $rates['rateUSD'] > 0 && (float) $rates['rateEURO'] > 0) {
                return ExchangeRate::query()->updateOrCreate(['date' => $date], ['usd_tzs' => $rates['rateUSD'], 'eur_tzs' => $rates['rateEURO']]);
            }
        }

        return null;
    }
}
