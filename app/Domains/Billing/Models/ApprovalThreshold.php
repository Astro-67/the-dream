<?php

namespace App\Domains\Billing\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $currency
 * @property int|null $amount
 */
#[Fillable(['currency', 'amount'])]
class ApprovalThreshold extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
