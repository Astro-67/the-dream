<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Actions\PostExtraCharge;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use LogicException;

/**
 * Refuses mass updates and deletes of postings, which would skip the model's
 * append-only guards, and owns the shared posting filters.
 *
 * @template TModel of Posting
 *
 * @extends Builder<TModel>
 */
class PostingBuilder extends Builder
{
    public function heldExtras(): static
    {
        return $this->whereIn('postings.type', ['charge', 'transfer_in'])->whereIn('postings.category', PostExtraCharge::CATEGORIES)
            ->whereNull('postings.vfms_receipt_number')->whereNull('postings.general_receipt_id')
            ->whereNotExists(fn (QueryBuilder $query) => $query->from('postings as cancelling')->whereColumn('cancelling.related_posting_id', 'postings.id'))
            ->orderBy('postings.posted_at')->orderBy('postings.id');
    }

    /** Money received or returned: payments (deposits included), refunds and reversals of them, all carrying a method. */
    public function moneyMovements(): static
    {
        return $this->whereNotNull($this->qualifyColumn('method'))->whereIn($this->qualifyColumn('type'), ['payment', 'refund', 'reversal']);
    }

    /** @param array<mixed> $values */
    public function update(array $values): never
    {
        throw new LogicException('Postings are append-only.');
    }

    public function delete(): never
    {
        throw new LogicException('Postings are append-only.');
    }
}
