<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\CashierShiftPolicy;
use App\Models\User;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's period of handling cash at one property (glossary: Cashier shift).
 * Amounts are minor units keyed by currency code. Expected cash is the float plus
 * cash taken minus cash paid out; it is frozen with the count when the shift closes.
 *
 * @property string $id
 * @property string $property_id
 * @property string $user_id
 * @property string|null $open_user_id the user while open, null once closed
 * @property string $status 'open' or 'closed'
 * @property array<string, int> $floats
 * @property array<string, int>|null $expected
 * @property array<string, int>|null $counted
 * @property string|null $variance_reason
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 */
#[Fillable(['user_id', 'open_user_id', 'status', 'floats', 'expected', 'counted', 'variance_reason', 'opened_at', 'closed_at'])]
#[UsePolicy(CashierShiftPolicy::class)]
class CashierShift extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['floats' => 'array', 'expected' => 'array', 'counted' => 'array', 'opened_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Float plus cash taken minus cash paid out, per currency. A cash payment is a
     * negative posting and cash paid out a positive one, so both subtract the amount.
     *
     * @return array<string, int>
     */
    public function expectedCash(): array
    {
        if ($this->expected !== null) {
            return $this->expected;
        }
        $expected = $this->floats;
        Posting::query()->where('cashier_shift_id', $this->id)->get(['amount', 'currency'])
            ->each(function (Posting $posting) use (&$expected) {
                $expected[$posting->currency->value] = ($expected[$posting->currency->value] ?? 0) - $posting->amount;
            });

        ksort($expected);

        return $expected;
    }

    /**
     * Counted minus expected per currency, once closed: negative is cash short.
     *
     * @return array<string, int>|null
     */
    public function variance(): ?array
    {
        if ($this->counted === null || $this->expected === null) {
            return null;
        }

        $variance = [];
        foreach ($this->expected as $currency => $amount) {
            $variance[$currency] = ($this->counted[$currency] ?? 0) - $amount;
        }

        return $variance;
    }
}
