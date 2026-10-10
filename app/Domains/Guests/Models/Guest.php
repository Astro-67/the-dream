<?php
namespace App\Domains\Guests\Models;
use App\Domains\Guests\Policies\GuestPolicy;
use App\Support\Tenancy\BelongsToProperty;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
/**
 * @property string $id
 * @property string $organization_id
 * @property string $property_id
 * @property string $name
 */
#[Fillable(['name', 'email', 'phone', 'nationality', 'preferences', 'notes'])]
#[UsePolicy(GuestPolicy::class)]
class Guest extends Model {
    use BelongsToProperty, HasUlids;
    public const FIELDS = ['name', 'email', 'phone', 'nationality', 'preferences', 'notes'];
}
