<?php

namespace App\Domains\FrontDesk\Models;

use App\Support\Tenancy\BelongsToProperty;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An item a close left unresolved, carried onto the next business date's
 * exception list (glossary: Exception list). A snapshot: resolving the subject
 * keeps it off the next close's list, not this one.
 *
 * @property string $id
 * @property string $property_id
 * @property string $business_date_close_id the close that carried it
 * @property CarbonImmutable $business_date the date whose list it is on
 * @property string $kind 'no_show', 'departure' or 'cashier_shift'
 * @property string $subject_id no-show proposal, reservation or cashier shift
 * @property string $reference reservation number or cashier name
 */
#[Fillable(['business_date_close_id', 'business_date', 'kind', 'subject_id', 'reference'])]
class ExceptionListItem extends Model
{
    use BelongsToProperty, HasUlids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['business_date' => 'immutable_date'];
    }
}
