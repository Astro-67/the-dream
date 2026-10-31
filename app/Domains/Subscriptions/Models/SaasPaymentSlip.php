<?php

namespace App\Domains\Subscriptions\Models;

use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $saas_invoice_id
 * @property string $path
 * @property string $original_name
 * @property string|null $reference
 * @property string|null $uploaded_by
 */
#[Fillable(['saas_invoice_id', 'path', 'original_name', 'reference', 'uploaded_by'])]
class SaasPaymentSlip extends Model
{
    use BelongsToTenant, HasUlids;
}
