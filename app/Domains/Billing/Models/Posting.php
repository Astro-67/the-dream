<?php

namespace App\Domains\Billing\Models;

use App\Models\User;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line on a folio. Append-only (FIN-02): a mistake is corrected by a linked
 * reversal, never by changing or removing the posting. Amounts are minor units of
 * $currency; a charge's tax lines and rule versions are frozen at posting (LOC-02).
 *
 * @property string $id
 * @property string $folio_id
 * @property string $type
 * @property string $category
 * @property string|null $description
 * @property int $quantity
 * @property int $unit_price
 * @property int $net
 * @property int $amount gross: net plus exclusive tax lines
 * @property Currency $currency
 * @property list<array{name: string, kind: string, inclusive: bool, rate: int|null, per_person_night: int|null, amount: int}> $tax_lines
 * @property list<string> $tax_rule_ids
 * @property string $idempotency_key
 * @property string|null $actor_id
 * @property CarbonImmutable $business_date
 * @property CarbonImmutable $posted_at
 */
#[Fillable(['folio_id', 'type', 'category', 'description', 'quantity', 'unit_price', 'net', 'amount', 'currency', 'tax_lines', 'tax_rule_ids', 'idempotency_key', 'actor_id', 'business_date', 'posted_at'])]
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

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Postings are append-only.'));
        static::deleting(fn () => throw new LogicException('Postings are append-only.'));
    }

    /**
     * Query builder that also refuses mass updates and deletes, which skip model events.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return Builder<static>
     */
    public function newEloquentBuilder($query): Builder
    {
        return new class($query) extends Builder
        {
            public function update(array $values)
            {
                throw new LogicException('Postings are append-only.');
            }

            public function delete()
            {
                throw new LogicException('Postings are append-only.');
            }
        };
    }
}
