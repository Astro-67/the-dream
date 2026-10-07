<?php

namespace App\Domains\Tenancy\Models;

use App\Domains\Tenancy\Data\MembershipGrant;
use App\Domains\Tenancy\Enums\InvitationStatus;
use App\Domains\Tenancy\Enums\OrganizationRole;
use App\Domains\Tenancy\Policies\InvitationPolicy;
use App\Models\User;
use App\Support\Tenancy\BelongsToTenant;
use App\Support\Tenancy\Exceptions\TenantMismatch;
use App\Support\Tenancy\Tenancy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join an organization with a role and the properties the
 * person may act in (PRD ORG-02). Accepted by signing in with the invited email.
 *
 * The link carries a secret token; only its SHA-256 hash is stored.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $email
 * @property OrganizationRole $role
 * @property bool $all_properties
 * @property list<string> $property_ids
 * @property string $token_hash
 * @property string|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'organization_id', 'email', 'role', 'all_properties', 'property_ids', 'token_hash',
    'invited_by', 'expires_at', 'accepted_at', 'accepted_by', 'revoked_at', 'revoked_by',
])]
#[Hidden(['token_hash'])]
#[UsePolicy(InvitationPolicy::class)]
class Invitation extends Model
{
    use BelongsToTenant, HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'all_properties' => 'boolean',
            'property_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The invitation an emailed link points to, looked up inside the inviting
     * organization's context (the invitee isn't a member yet). Null when the
     * organization or the token is unknown.
     */
    public static function findByLink(string $organizationId, string $token): ?self
    {
        try {
            return Tenancy::within(
                TenantContext::fromIds($organizationId),
                fn () => self::query()->where('token_hash', self::hashToken($token))->first(),
            );
        } catch (TenantMismatch) {
            return null;
        }
    }

    public function status(): InvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => InvitationStatus::Accepted,
            $this->revoked_at !== null => InvitationStatus::Revoked,
            $this->expires_at->isPast() => InvitationStatus::Expired,
            default => InvitationStatus::Pending,
        };
    }

    public function grant(): MembershipGrant
    {
        return $this->all_properties
            ? MembershipGrant::allProperties($this->role)
            : MembershipGrant::properties($this->role, $this->property_ids);
    }

    public function isFor(User $user): bool
    {
        return mb_strtolower($user->email) === $this->email;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
