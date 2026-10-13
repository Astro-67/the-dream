<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\FolioPolicy;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
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
#[UsePolicy(FolioPolicy::class)]
class Folio extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['currency' => Currency::class];
    }

    /**
     * What the guest owes on this folio: charges add, and later payments and credits subtract.
     * ponytail: every posting is a charge until payments land; give credits negative amounts then.
     */
    public function balance(): Money
    {
        return $this->postings->reduce(fn (Money $sum, Posting $posting) => $sum->plus($posting->money()), new Money(0, $this->currency));
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class)->orderBy('posted_at')->orderBy('id');
    }
}
