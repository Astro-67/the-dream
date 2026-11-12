<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\FolioPolicy;
use App\Domains\Reservations\Models\Reservation;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The guest account for one reservation in one currency (glossary: Folio).
 *
 * @property string $id
 * @property string $property_id
 * @property string|null $reservation_id
 * @property string|null $customer_account_id
 * @property Currency $currency
 * @property-read Reservation|null $reservation
 * @property-read CustomerAccount|null $customerAccount
 */
#[Fillable(['reservation_id', 'customer_account_id', 'currency'])]
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
     */
    public function balance(): Money
    {
        return new Money((int) $this->postings()->sum('amount'), $this->currency);
    }

    /** @return HasMany<Posting, $this> */
    public function postings(): HasMany
    {
        return $this->hasMany(Posting::class)->orderBy('posted_at')->orderBy('id');
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function lockOwner(): Reservation|CustomerAccount
    {
        if ($this->customer_account_id === null) {
            return Reservation::query()->whereKey($this->reservation_id)->lockForUpdate()->firstOrFail();
        }
        $account = CustomerAccount::query()->whereKey($this->customer_account_id)->lockForUpdate()->firstOrFail();
        $account->ensureOpen();

        return $account;
    }

    public function url(): string
    {
        return $this->customer_account_id === null
            ? route('reservations.show', $this->reservation()->firstOrFail())
            : route('customer-accounts.show', $this->customer_account_id);
    }
}
