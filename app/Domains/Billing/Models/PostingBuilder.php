<?php

namespace App\Domains\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Refuses mass updates and deletes of postings, which would skip the model's
 * append-only guards.
 *
 * @template TModel of Posting
 *
 * @extends Builder<TModel>
 */
class PostingBuilder extends Builder
{
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
