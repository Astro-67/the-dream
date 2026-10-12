<?php

namespace App\Domains\Billing\Models;

use App\Support\Money\Currency;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The guest account for one reservation in one currency (glossary: Folio).
 *
 * @property string $id
 * @property string $property_id
 * @property string $reservation_id
 * @property Currency $currency
 */
#[Fillable(['reservation_id', 'currency'])]
class Folio extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency' => Currency::class];
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class)->orderBy('posted_at')->orderBy('id');
    }
}
