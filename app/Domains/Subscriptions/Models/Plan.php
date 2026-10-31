<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Enums\BillingPeriod;
use App\Domains\Subscriptions\Policies\PlanPolicy;
use App\Support\Money\Currency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * What an organization subscribes to (PRD SUB-02). Platform data: belongs to no
 * organization, and only platform administrators change it.
 *
 * @property string $id
 * @property string $name
 * @property string $code
 * @property int|null $property_limit null means no limit
 * @property int $trial_days
 * @property int $grace_days days past an invoice's due date before suspension
 * @property BillingPeriod $billing_period
 * @property int $price minor units of $currency per subscribed room per billing period
 * @property Currency $currency
 * @property list<string> $modules module entitlements; unused in M2
 * @property bool $is_default the plan new organizations start their trial on
 */
#[Fillable(['name', 'code', 'property_limit', 'trial_days', 'grace_days', 'billing_period', 'price', 'currency', 'modules', 'is_default'])]
#[UsePolicy(PlanPolicy::class)]
class Plan extends Model
{
    use HasUlids;

    public const FIELDS = ['name', 'code', 'property_limit', 'trial_days', 'grace_days', 'billing_period', 'price', 'currency', 'is_default'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'property_limit' => 'integer', 'trial_days' => 'integer', 'grace_days' => 'integer',
            'billing_period' => BillingPeriod::class, 'price' => 'integer', 'currency' => Currency::class,
            'modules' => 'array', 'is_default' => 'boolean',
        ];
    }

    public static function default(): self
    {
        return self::query()->where('is_default', true)->orderBy('created_at')->firstOrFail();
    }
}
