<?php

namespace App\Domains\FiscalReceipting\Models;

use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * A fiscal document VFMS issued, as projected by IssuedDocuments from delivered outbox evidence.
 *
 * @property string $id
 * @property 'services_receipt'|'checkout_report' $type
 * @property string $number
 * @property 'live'|'sandbox' $mode
 */
#[Fillable(['outbox_message_id', 'outbox_attempt_id', 'reservation_id', 'customer_account_id', 'posting_id', 'type', 'number', 'mode', 'issued_at', 'amount', 'currency', 'guest_name', 'viewer_url'])]
class IssuedDocument extends Model
{
    use BelongsToProperty, HasUlids;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Issued document evidence is never rewritten.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['issued_at' => 'immutable_datetime', 'amount' => 'integer'];
    }
}
