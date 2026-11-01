<?php

namespace App\Support\Money;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * ZRA's exchange rates for one date, as VFMS returns them (guide v1.6 §2.4): TZS per USD and per EUR.
 * National rates, shared by every property.
 *
 * @property string $id
 * @property CarbonImmutable $date
 * @property string $usd_tzs
 * @property string $eur_tzs
 */
#[Fillable(['date', 'usd_tzs', 'eur_tzs'])]
class ExchangeRate extends Model
{
    use HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'usd_tzs' => 'decimal:6', 'eur_tzs' => 'decimal:6'];
    }

    /** The rate in force on a Y-m-d date: the latest fetched on or before it, else the earliest fetched. */
    public static function forDate(string $date): ?self
    {
        return self::query()->whereDate('date', '<=', $date)->orderByDesc('date')->first()
            ?? self::query()->orderBy('date')->first();
    }

    /** USD minor units in $currency's minor units (TZS has none, EUR two), rounded half up. */
    public function fromUsd(int $cents, Currency $currency): int
    {
        return (int) round(match ($currency) {
            Currency::USD => $cents,
            Currency::TZS => $cents * (float) $this->usd_tzs / 100,
            Currency::EUR => $cents * (float) $this->usd_tzs / (float) $this->eur_tzs,
        });
    }
}
