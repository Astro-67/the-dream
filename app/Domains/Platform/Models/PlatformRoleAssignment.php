<?php

namespace App\Domains\Platform\Models;

use App\Domains\Platform\Enums\PlatformRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform role held by a user. Not tenant data: it belongs to no organization.
 * Created and removed only through the Platform actions.
 *
 * @property string $id
 * @property string $user_id
 * @property PlatformRole $role
 * @property string|null $assigned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'role', 'assigned_by'])]
class PlatformRoleAssignment extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['role' => PlatformRole::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
