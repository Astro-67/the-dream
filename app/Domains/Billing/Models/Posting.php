<?php

namespace App\Domains\Billing\Models;

use App\Domains\Reservations\Models\Reservation;
use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * One line on a folio. Append-only (FIN-02): a mistake is corrected by a linked
 * reversal, never by changing or removing the posting. Amounts are minor units of
 * $currency; a charge's tax lines and rule versions are frozen at posting (LOC-02).
 *
 * @property string $id
 * @property string $folio_id
 * @property string|null $reservation_id
 * @property string|null $price_line_id
 * @property string|null $related_posting_id the posting this one reverses or transfers
 * @property string $type
 * @property string $category
 * @property string|null $description
 * @property string|null $reason why a reversal or transfer was posted
 * @property int $quantity
 * @property int $unit_price
 * @property int $net
 * @property int $amount gross: net plus exclusive tax lines
 * @property Currency $currency
 * @property list<array{name: string, category?: string|null, kind: string, inclusive: bool, rate: int|null, per_person_night: int|null, amount: int}> $tax_lines
 * @property list<string> $tax_rule_ids
 * @property string $idempotency_key
 * @property string|null $actor_id
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $posted_at
 * @property string|null $method a payment's method
 * @property string|null $reference
 * @property string|null $card_last_four
 * @property string|null $card_brand
 * @property string|null $cashier_shift_id the shift whose cash drawer a cash posting went through
 * @property-read Receipt|null $receipt
 * @property-read Posting|null $related
 * @property-read Posting|null $cancelledBy
 * @property-read Reservation|null $reservation
 */
#[Fillable(['folio_id', 'reservation_id', 'price_line_id', 'related_posting_id', 'type', 'category', 'description', 'reason', 'quantity', 'unit_price', 'net', 'amount', 'currency', 'tax_lines', 'tax_rule_ids', 'idempotency_key', 'actor_id', 'business_date', 'posted_at', 'method', 'reference', 'card_last_four', 'card_brand', 'cashier_shift_id'])]
#[UseEloquentBuilder(PostingBuilder::class)]
class Posting extends Model
{
    use BelongsToProperty, HasUlids;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_price' => 'integer', 'net' => 'integer', 'amount' => 'integer',
            'currency' => Currency::class, 'tax_lines' => 'array', 'tax_rule_ids' => 'array',
            'business_date' => 'immutable_date', 'posted_at' => 'immutable_datetime',
        ];
    }

    public function money(): Money
    {
        return new Money($this->amount, $this->currency);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return HasOne<Receipt, $this> */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /** @return BelongsTo<Posting, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(self::class, 'related_posting_id');
    }

    /** @return HasOne<Posting, $this> */
    public function cancelledBy(): HasOne
    {
        return $this->hasOne(self::class, 'related_posting_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Postings are append-only.'));
        static::deleting(fn () => throw new LogicException('Postings are append-only.'));
    }
}
