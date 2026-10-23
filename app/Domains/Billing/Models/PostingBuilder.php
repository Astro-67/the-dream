<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
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
