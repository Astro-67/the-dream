<?php

namespace App\Domains\Billing\Models;

use App\Domains\Billing\Policies\AccountPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @property string $id
 * @property string $property_id
 * @property string $number
 * @property string $kind
 * @property string $customer_name
 * @property string $status
 */
#[Fillable(['number', 'kind', 'customer_name', 'paying_institution_type', 'paying_institution_name', 'paying_institution_zrb_number', 'starts_on', 'ends_on', 'note', 'status'])]
#[UsePolicy(AccountPolicy::class)]
class CustomerAccount extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return HasMany<Folio, $this> */
    public function folios(): HasMany
    {
        return $this->hasMany(Folio::class);
    }

    public function ensureOpen(): void
    {
        if ($this->status !== 'open') {
            throw ValidationException::withMessages(['account' => __('This customer account is closed.')]);
        }
    }
}
