<?php

namespace App\Domains\Tenancy\Models;

use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's membership of an organization. The role is a spatie role in the
 * organization's team (ADR 0007); which properties the member may act in is
 * all_properties or their PropertyMembership rows.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $user_id
 * @property bool $all_properties
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organization_id', 'user_id', 'all_properties'])]
class OrganizationMembership extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['all_properties' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
