<?php

namespace App\Domains\Subscriptions\Models;

use App\Domains\Subscriptions\Policies\SaasInvoicePolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Thedream's business details and the payment accounts it accepts. Platform
 * data: one row, belonging to no organization. Each new SaaS invoice keeps a
 * snapshot of it, so later edits never change an issued invoice.
 *
 * @property string $id
 * @property string $business_name
 * @property string $address
 * @property string $phone
 * @property string $email
 * @property string $tin
 * @property string|null $vrn
 * @property list<array<string, string|null>> $accounts each a bank (bank, branch, swift, account_name, account_number) or mobile_money (provider, account_name, account_number) account, keyed by `type`
 */
#[Fillable(self::FIELDS)]
#[UsePolicy(SaasInvoicePolicy::class)]
class BillingDetails extends Model
{
    use HasUlids;

    public const array FIELDS = ['business_name', 'address', 'phone', 'email', 'tin', 'vrn', 'accounts'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['accounts' => 'array'];
    }

    public static function current(): ?self
    {
        return self::query()->orderBy('created_at')->first();
    }

    /**
     * What a new invoice stores, or null while the details or every account are missing.
     *
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array
    {
        $details = self::current();

        return $details === null || $details->accounts === [] ? null : $details->only(self::FIELDS);
    }
}
